<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeInvoicePaymentService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listPaymentMethods(Invoice $invoice): array
    {
        $invoice->loadMissing('clientAccount');
        $account = $invoice->clientAccount;
        if ($account === null) {
            throw new \RuntimeException('Invoice account is unavailable.');
        }
        $customerId = trim((string) ($account->stripe_customer_id ?? ''));
        if ($customerId === '') {
            throw new \RuntimeException('Stripe Customer ID is missing for this account.');
        }

        $stripe = $this->client();
        try {
            $customer = $stripe->customers->retrieve($customerId, []);
            $customerArr = is_array($customer) ? $customer : $customer->toArray();
            if (!empty($customerArr['deleted'])) {
                throw new \RuntimeException('Stripe customer not found.');
            }
            $defaultPm = trim((string) (($customerArr['invoice_settings']['default_payment_method'] ?? '')));

            $cards = $stripe->paymentMethods->all([
                'customer' => $customerId,
                'type' => 'card',
            ]);
            $banks = $stripe->paymentMethods->all([
                'customer' => $customerId,
                'type' => 'us_bank_account',
            ]);
            $all = array_merge($cards->data ?? [], $banks->data ?? []);
            $rows = [];
            foreach ($all as $pm) {
                $arr = is_array($pm) ? $pm : $pm->toArray();
                $id = trim((string) ($arr['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $type = trim((string) ($arr['type'] ?? ''));
                $label = $type === 'us_bank_account'
                    ? $this->bankLabel($arr)
                    : $this->cardLabel($arr);
                $rows[] = [
                    'id' => $id,
                    'label' => $label,
                    'type' => $type,
                    'is_default' => $defaultPm !== '' && hash_equals($defaultPm, $id),
                ];
            }
            usort($rows, static function (array $a, array $b): int {
                return (int) ($b['is_default'] ?? false) <=> (int) ($a['is_default'] ?? false);
            });
            return $rows;
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $paymentMeta
     * @return array<string, mixed>
     */
    public function chargeInvoice(
        Invoice $invoice,
        string $paymentMethodId,
        ?int $amountCents,
        ?User $actor,
        array $paymentMeta,
        InvoiceService $invoiceService
    ): array {
        $invoice->loadMissing('clientAccount');
        $account = $invoice->clientAccount;
        if ($account === null) {
            throw new \RuntimeException('Invoice account is unavailable.');
        }
        $customerId = trim((string) ($account->stripe_customer_id ?? ''));
        if ($customerId === '') {
            throw new \RuntimeException('Stripe Customer ID is missing for this account.');
        }

        $balance = (int) $invoice->balance_due_cents;
        $chargeAmount = $amountCents ?? $balance;
        if ($chargeAmount <= 0) {
            throw new \RuntimeException('Invoice has no balance due.');
        }

        $currency = strtolower((string) ($invoice->currency ?: 'usd'));
        if ($currency !== 'usd') {
            throw new \RuntimeException('Stripe charge currently supports USD invoices only.');
        }
        $paymentTitle = $this->invoiceTitle($invoice);

        $stripe = $this->client();
        $pmId = trim($paymentMethodId);
        if ($pmId === '') {
            throw new \RuntimeException('Invalid Stripe payment method selected.');
        }

        try {
            $intent = $stripe->paymentIntents->create([
                'amount' => $chargeAmount,
                'currency' => $currency,
                'customer' => $customerId,
                'payment_method' => $pmId,
                'confirm' => true,
                'off_session' => true,
                'description' => $paymentTitle,
                'metadata' => [
                    'source' => 'new_crm_invoice',
                    'invoice_id' => (string) $invoice->id,
                    'invoice_number' => (string) $invoice->invoice_number,
                    'client_account_id' => (string) $invoice->client_account_id,
                ],
                'receipt_email' => filter_var((string) $account->email, FILTER_VALIDATE_EMAIL) ? (string) $account->email : null,
            ]);
        } catch (ApiErrorException $e) {
            $fromStatus = (string) $invoice->status;
            if ($invoice->status !== Invoice::STATUS_PAYMENT_FAILED) {
                $invoice->status = Invoice::STATUS_PAYMENT_FAILED;
                $invoice->paid_at = null;
                $invoice->save();
            }
            $invoiceService->logHistory($invoice, $actor, 'payment_applied', $fromStatus, $invoice->status, [
                'event_type' => 'status',
                'history_message' => 'Stripe payment failed.',
                'stripe_status' => 'api_error',
                'stripe_error_message' => $e->getMessage(),
            ] + $paymentMeta);
            throw new \RuntimeException($e->getMessage());
        }

        return $this->applyIntentToInvoice($invoice, $intent, $actor, $paymentMeta, $invoiceService);
    }

    /**
     * @param array<string, mixed> $paymentMeta
     * @return array<string, mixed>
     */
    public function applyIntentToInvoice(
        Invoice $invoice,
        PaymentIntent $intent,
        ?User $actor,
        array $paymentMeta,
        InvoiceService $invoiceService
    ): array {
        $intentArr = $intent->toArray();
        $status = strtolower((string) ($intentArr['status'] ?? ''));
        $intentId = trim((string) ($intentArr['id'] ?? ''));
        $chargeId = trim((string) (($intentArr['latest_charge'] ?? '')));
        $amountReceived = (int) ($intentArr['amount_received'] ?? 0);
        if ($amountReceived <= 0) {
            $amountReceived = (int) ($intentArr['amount'] ?? 0);
        }

        $meta = $paymentMeta + [
            'stripe_payment_intent' => $intentId,
            'stripe_charge_id' => $chargeId !== '' ? $chargeId : null,
            'stripe_status' => $status,
        ];

        if ($status === 'succeeded') {
            $apply = min((int) $invoice->balance_due_cents, $amountReceived);
            if ($apply > 0) {
                $updated = $invoiceService->recordPayment($invoice, $apply, $actor, $meta);
            } else {
                $updated = $invoice->fresh(['items', 'histories.user', 'clientAccount', 'createdBy']) ?? $invoice;
            }

            return [
                'result' => 'succeeded',
                'invoice' => $updated,
                'applied_amount_cents' => $apply,
                'payment_intent_id' => $intentId,
                'status' => $status,
            ];
        }

        if ($status === 'processing' || $status === 'requires_action' || $status === 'requires_capture') {
            $fromStatus = (string) $invoice->status;
            if ($invoice->status !== Invoice::STATUS_PROCESSING) {
                $invoice->status = Invoice::STATUS_PROCESSING;
                $invoice->paid_at = null;
                $invoice->save();
            }
            $invoiceService->logHistory($invoice, $actor, 'payment_applied', $fromStatus, $invoice->status, [
                'event_type' => 'status',
                'history_message' => 'Stripe payment submitted and pending settlement.',
            ] + $meta);

            return [
                'result' => 'pending',
                'invoice' => $invoice->fresh(['items', 'histories.user', 'clientAccount', 'createdBy']) ?? $invoice,
                'applied_amount_cents' => 0,
                'payment_intent_id' => $intentId,
                'status' => $status,
            ];
        }

        $fromStatus = (string) $invoice->status;
        if ($invoice->status !== Invoice::STATUS_PAYMENT_FAILED) {
            $invoice->status = Invoice::STATUS_PAYMENT_FAILED;
            $invoice->paid_at = null;
            $invoice->save();
        }
        $invoiceService->logHistory($invoice, $actor, 'payment_applied', $fromStatus, $invoice->status, [
            'event_type' => 'status',
            'history_message' => 'Stripe payment failed.',
        ] + $meta);

        return [
            'result' => 'failed',
            'invoice' => $invoice->fresh(['items', 'histories.user', 'clientAccount', 'createdBy']) ?? $invoice,
            'applied_amount_cents' => 0,
            'payment_intent_id' => $intentId,
            'status' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function handleWebhook(string $payload, string $signature, string $secret, InvoiceService $invoiceService): array
    {
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException $e) {
            throw new \RuntimeException('Invalid Stripe signature.');
        } catch (\UnexpectedValueException $e) {
            throw new \RuntimeException('Invalid Stripe payload.');
        }

        $type = (string) $event->type;
        if ($type !== 'payment_intent.succeeded' && $type !== 'payment_intent.payment_failed') {
            return ['handled' => true, 'event_type' => $type, 'applied' => false];
        }

        /** @var PaymentIntent $intent */
        $intent = $event->data->object;
        $meta = $intent->metadata;
        $invoiceId = (int) ($meta['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            return ['handled' => true, 'event_type' => $type, 'applied' => false];
        }
        $invoice = Invoice::query()->find($invoiceId);
        if ($invoice === null || $invoice->isVoid()) {
            return ['handled' => true, 'event_type' => $type, 'applied' => false];
        }

        $intentId = trim((string) ($intent->id ?? ''));
        if ($intentId !== '') {
            $already = $invoice->histories()
                ->where('action', 'payment_applied')
                ->where('meta->stripe_payment_intent', $intentId)
                ->where('meta->stripe_status', 'succeeded')
                ->exists();
            if ($already) {
                return ['handled' => true, 'event_type' => $type, 'applied' => false, 'duplicate' => true];
            }
        }

        if ($type === 'payment_intent.payment_failed') {
            $fromStatus = (string) $invoice->status;
            if ($invoice->status !== Invoice::STATUS_PAYMENT_FAILED) {
                $invoice->status = Invoice::STATUS_PAYMENT_FAILED;
                $invoice->paid_at = null;
                $invoice->save();
            }
            $invoiceService->logHistory($invoice, null, 'payment_applied', $fromStatus, $invoice->status, [
                'event_type' => 'status',
                'history_message' => 'Stripe payment failed.',
                'stripe_payment_intent' => $intentId !== '' ? $intentId : null,
                'stripe_status' => 'payment_failed',
            ]);

            return [
                'handled' => true,
                'event_type' => $type,
                'applied' => false,
            ];
        }

        $result = $this->applyIntentToInvoice($invoice, $intent, null, [], $invoiceService);

        return [
            'handled' => true,
            'event_type' => $type,
            'applied' => ($result['result'] ?? '') === 'succeeded',
        ];
    }

    public function createPublicCheckoutUrl(Invoice $invoice, string $successUrl, string $cancelUrl): string
    {
        $invoice->loadMissing('clientAccount');
        $account = $invoice->clientAccount;
        if ($account === null) {
            throw new \RuntimeException('Invoice account is unavailable.');
        }
        $balance = (int) $invoice->balance_due_cents;
        if ($balance <= 0) {
            throw new \RuntimeException('Invoice has no balance due.');
        }
        $currency = strtolower((string) ($invoice->currency ?: 'usd'));
        if ($currency !== 'usd') {
            throw new \RuntimeException('Public checkout currently supports USD invoices only.');
        }
        $checkoutTitle = $this->invoiceTitle($invoice);

        $stripe = $this->client();
        $customerId = trim((string) ($account->stripe_customer_id ?? ''));
        if ($customerId === '') {
            try {
                $created = $stripe->customers->create([
                    'name' => (string) ($account->company_name ?: 'Save Rack Customer'),
                    'email' => filter_var((string) $account->email, FILTER_VALIDATE_EMAIL) ? (string) $account->email : null,
                    'metadata' => [
                        'source' => 'new_crm_public_invoice',
                        'client_account_id' => (string) $account->id,
                    ],
                ]);
            } catch (ApiErrorException $e) {
                throw new \RuntimeException($e->getMessage());
            }
            $customerId = trim((string) ($created->id ?? ''));
            if ($customerId === '') {
                throw new \RuntimeException('Could not create Stripe customer.');
            }
            $account->stripe_customer_id = $customerId;
            $account->save();
        }

        try {
            $session = $stripe->checkout->sessions->create([
                'mode' => 'payment',
                'payment_method_types' => ['card', 'us_bank_account'],
                'customer' => $customerId,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $balance,
                        'product_data' => [
                            'name' => $checkoutTitle,
                            'description' => 'Save Rack invoice payment',
                        ],
                    ],
                ]],
                'payment_intent_data' => [
                    'description' => $checkoutTitle,
                    'metadata' => [
                        'source' => 'new_crm_public_checkout',
                        'invoice_id' => (string) $invoice->id,
                        'invoice_number' => (string) $invoice->invoice_number,
                        'client_account_id' => (string) $invoice->client_account_id,
                        'checkout_session_ref' => (string) Str::uuid(),
                    ],
                ],
            ]);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage());
        }

        $url = trim((string) ($session->url ?? ''));
        if ($url === '') {
            throw new \RuntimeException('Stripe checkout URL was not returned.');
        }

        return $url;
    }

    private function client(): StripeClient
    {
        $secret = trim((string) config('services.stripe.secret', ''));
        if ($secret === '') {
            throw new \RuntimeException('Stripe secret is not configured.');
        }

        return new StripeClient($secret);
    }

    private function invoiceTitle(Invoice $invoice): string
    {
        return 'Invoice # '.$invoice->invoice_number.' - Save Rack';
    }

    /**
     * @param array<string, mixed> $pm
     */
    private function cardLabel(array $pm): string
    {
        $card = $pm['card'] ?? [];
        $brand = strtoupper(trim((string) ($card['brand'] ?? 'CARD')));
        $last4 = trim((string) ($card['last4'] ?? ''));
        $expMonth = (int) ($card['exp_month'] ?? 0);
        $expYear = (int) ($card['exp_year'] ?? 0);
        $label = $brand.' **** '.$last4;
        if ($expMonth > 0 && $expYear > 0) {
            $label .= ' (exp '.str_pad((string) $expMonth, 2, '0', STR_PAD_LEFT).'/'.substr((string) $expYear, -2).')';
        }

        return $label;
    }

    /**
     * @param array<string, mixed> $pm
     */
    private function bankLabel(array $pm): string
    {
        $bank = $pm['us_bank_account'] ?? [];
        $bankName = trim((string) ($bank['bank_name'] ?? 'BANK'));
        $last4 = trim((string) ($bank['last4'] ?? ''));

        return $bankName.' **** '.$last4;
    }
}
