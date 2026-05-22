<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;

class PortalOnboardingStripeService
{
    public const METADATA_PURPOSE = 'portal_onboarding_deposit';

    /** @var PortalOnboardingService */
    protected $onboarding;

    public function __construct(PortalOnboardingService $onboarding)
    {
        $this->onboarding = $onboarding;
    }

    public function createCheckoutSession(ClientAccount $account, User $user, string $method): string
    {
        $method = $method === PortalOnboardingService::BILLING_METHOD_ACH
            ? PortalOnboardingService::BILLING_METHOD_ACH
            : PortalOnboardingService::BILLING_METHOD_CREDIT_CARD;

        $configuredLink = trim((string) config('crm.stripe_onboarding_payment_link_url', ''));
        if ($configuredLink !== '') {
            $separator = strpos($configuredLink, '?') !== false ? '&' : '?';

            return $configuredLink.$separator.http_build_query([
                'client_reference_id' => (string) $account->id,
            ]);
        }

        $amountCents = max(50, (int) config('crm.stripe_onboarding_deposit_amount_cents', 500));
        $priceId = trim((string) config('crm.stripe_onboarding_price_id', ''));
        $stripe = $this->client();
        $customerId = $this->ensureStripeCustomer($stripe, $account, $user);

        $successUrl = $this->onboarding->welcomeBillingReturnUrl('success');
        $cancelUrl = $this->onboarding->welcomeBillingReturnUrl('cancel');

        $lineItems = $priceId !== ''
            ? [['price' => $priceId, 'quantity' => 1]]
            : [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => $amountCents,
                    'product_data' => [
                        'name' => 'Save Rack authorization deposit',
                        'description' => 'Credited toward your next invoice',
                    ],
                ],
            ]];

        try {
            $session = $stripe->checkout->sessions->create([
                'mode' => 'payment',
                'customer' => $customerId,
                'payment_method_types' => ['card', 'us_bank_account'],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => (string) $account->id,
                'line_items' => $lineItems,
                'payment_intent_data' => [
                    'setup_future_usage' => 'off_session',
                    'metadata' => [
                        'purpose' => self::METADATA_PURPOSE,
                        'client_account_id' => (string) $account->id,
                        'billing_method' => $method,
                        'user_id' => (string) $user->id,
                    ],
                ],
                'metadata' => [
                    'purpose' => self::METADATA_PURPOSE,
                    'client_account_id' => (string) $account->id,
                    'billing_method' => $method,
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

    /**
     * @return array<string, mixed>|null Null when event is not portal onboarding.
     */
    public function tryHandleEvent(Event $event): ?array
    {
        $type = (string) $event->type;
        $stripe = $this->client();

        if (str_starts_with($type, 'checkout.session.')) {
            /** @var CheckoutSession $session */
            $session = $event->data->object;
            $meta = $this->sessionMetadata($session);
            if (($meta['purpose'] ?? '') !== self::METADATA_PURPOSE) {
                return null;
            }

            $accountId = (int) ($meta['client_account_id'] ?? $session->client_reference_id ?? 0);
            if ($accountId <= 0) {
                return ['handled' => true, 'event_type' => $type, 'applied' => false];
            }

            if ($type === 'checkout.session.async_payment_failed') {
                $this->markBillingFailed($accountId);

                return ['handled' => true, 'event_type' => $type, 'applied' => true];
            }

            if ($type === 'checkout.session.async_payment_succeeded') {
                $this->completeBillingFromSession($stripe, $session, $accountId, $meta);

                return ['handled' => true, 'event_type' => $type, 'applied' => true];
            }

            if ($type === 'checkout.session.completed') {
                $method = (string) ($meta['billing_method'] ?? '');
                $paymentStatus = (string) ($session->payment_status ?? '');
                if ($method === PortalOnboardingService::BILLING_METHOD_ACH && $paymentStatus !== 'paid') {
                    $this->markBillingProcessing($accountId, $method);

                    return ['handled' => true, 'event_type' => $type, 'applied' => true];
                }
                $this->completeBillingFromSession($stripe, $session, $accountId, $meta);

                return ['handled' => true, 'event_type' => $type, 'applied' => true];
            }

            return ['handled' => true, 'event_type' => $type, 'applied' => false];
        }

        if ($type === 'payment_intent.succeeded' || $type === 'payment_intent.payment_failed') {
            /** @var PaymentIntent $intent */
            $intent = $event->data->object;
            $meta = is_array($intent->metadata) ? $intent->metadata->toArray() : [];
            if (($meta['purpose'] ?? '') !== self::METADATA_PURPOSE) {
                return null;
            }
            $accountId = (int) ($meta['client_account_id'] ?? 0);
            if ($accountId <= 0) {
                return ['handled' => true, 'event_type' => $type, 'applied' => false];
            }
            if ($type === 'payment_intent.payment_failed') {
                $this->markBillingFailed($accountId);

                return ['handled' => true, 'event_type' => $type, 'applied' => true];
            }
            $this->completeBillingFromPaymentIntent($stripe, $intent, $accountId, $meta);

            return ['handled' => true, 'event_type' => $type, 'applied' => true];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function handleWebhook(string $payload, string $signature, string $secret): array
    {
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Invalid Stripe webhook: '.$e->getMessage());
        }

        $result = $this->tryHandleEvent($event);

        return $result ?? ['handled' => true, 'event_type' => (string) $event->type, 'applied' => false];
    }

    private function completeBillingFromSession(StripeClient $stripe, CheckoutSession $session, int $accountId, array $meta): void
    {
        $intentRef = $session->payment_intent ?? null;
        if ($intentRef === null || $intentRef === '') {
            $this->completeBillingFromCustomer($stripe, (string) $session->customer, $accountId, $meta);

            return;
        }
        $intent = $intentRef instanceof PaymentIntent
            ? $intentRef
            : $stripe->paymentIntents->retrieve((string) $intentRef, []);
        $intentMeta = is_array($intent->metadata) ? $intent->metadata->toArray() : [];
        $merged = array_merge($meta, $intentMeta);
        $this->completeBillingFromPaymentIntent($stripe, $intent, $accountId, $merged);
    }

    private function completeBillingFromPaymentIntent(StripeClient $stripe, PaymentIntent $intent, int $accountId, array $meta): void
    {
        $account = ClientAccount::query()->find($accountId);
        if ($account === null) {
            return;
        }
        $customerId = trim((string) ($intent->customer ?? ''));
        if ($customerId !== '') {
            $account->stripe_customer_id = $customerId;
        }
        $pmId = trim((string) ($intent->payment_method ?? ''));
        if ($pmId !== '' && $customerId !== '') {
            try {
                $stripe->customers->update($customerId, [
                    'invoice_settings' => ['default_payment_method' => $pmId],
                ]);
            } catch (ApiErrorException $e) {
                // Customer may still be usable without default PM.
            }
        }
        $method = (string) ($meta['billing_method'] ?? $account->onboarding_billing_method ?? '');
        $account->onboarding_billing_method = $method !== '' ? $method : PortalOnboardingService::BILLING_METHOD_CREDIT_CARD;
        $account->default_payment_type = $account->onboarding_billing_method === PortalOnboardingService::BILLING_METHOD_ACH
            ? 'ACH'
            : 'Credit Card';
        $account->onboarding_billing_status = PortalOnboardingService::BILLING_STATUS_COMPLETED;
        $account->save();
    }

    private function completeBillingFromCustomer(StripeClient $stripe, string $customerId, int $accountId, array $meta): void
    {
        if ($customerId === '') {
            return;
        }
        $account = ClientAccount::query()->find($accountId);
        if ($account === null) {
            return;
        }
        $account->stripe_customer_id = $customerId;
        $method = (string) ($meta['billing_method'] ?? PortalOnboardingService::BILLING_METHOD_CREDIT_CARD);
        $account->onboarding_billing_method = $method;
        $account->default_payment_type = $method === PortalOnboardingService::BILLING_METHOD_ACH ? 'ACH' : 'Credit Card';
        $account->onboarding_billing_status = PortalOnboardingService::BILLING_STATUS_COMPLETED;
        $account->save();
    }

    private function markBillingProcessing(int $accountId, string $method): void
    {
        $account = ClientAccount::query()->find($accountId);
        if ($account === null) {
            return;
        }
        $account->onboarding_billing_method = $method;
        $account->onboarding_billing_status = PortalOnboardingService::BILLING_STATUS_PROCESSING;
        $account->save();
    }

    private function markBillingFailed(int $accountId): void
    {
        $account = ClientAccount::query()->find($accountId);
        if ($account === null) {
            return;
        }
        $account->onboarding_billing_status = PortalOnboardingService::BILLING_STATUS_FAILED;
        $account->save();
    }

    /**
     * @return array<string, string>
     */
    private function sessionMetadata(CheckoutSession $session): array
    {
        $meta = $session->metadata ?? null;
        if ($meta === null) {
            return [];
        }

        return is_array($meta) ? $meta : $meta->toArray();
    }

    private function ensureStripeCustomer(StripeClient $stripe, ClientAccount $account, User $user): string
    {
        $customerId = trim((string) ($account->stripe_customer_id ?? ''));
        if ($customerId !== '') {
            return $customerId;
        }
        $email = filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)
            ? (string) $user->email
            : (filter_var((string) $account->email, FILTER_VALIDATE_EMAIL) ? (string) $account->email : null);
        try {
            $created = $stripe->customers->create([
                'name' => (string) ($account->company_name ?: $user->name ?: 'Save Rack Customer'),
                'email' => $email,
                'metadata' => [
                    'source' => 'portal_onboarding',
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

        return $customerId;
    }

    private function client(): StripeClient
    {
        $secret = trim((string) config('services.stripe.secret', ''));
        if ($secret === '') {
            throw new \RuntimeException('Stripe is not configured.');
        }

        return new StripeClient($secret);
    }
}
