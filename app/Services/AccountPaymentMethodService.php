<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\PaymentMethodLink;
use App\Models\User;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class AccountPaymentMethodService
{
    /** @var StripeInvoicePaymentService */
    private $stripePayments;

    public function __construct(StripeInvoicePaymentService $stripePayments)
    {
        $this->stripePayments = $stripePayments;
    }

    public function publishableKey(): string
    {
        return trim((string) config('services.stripe.key', ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function createLink(
        ClientAccount $account,
        string $method,
        ?User $actor = null,
        ?string $replacePaymentMethodId = null
    ): array {
        $method = $method === PaymentMethodLink::METHOD_ACH
            ? PaymentMethodLink::METHOD_ACH
            : PaymentMethodLink::METHOD_CREDIT_CARD;

        $hours = max(1, (int) config('crm.payment_method_link_ttl_hours', 48));
        $token = Str::random(48);

        $link = PaymentMethodLink::query()->create([
            'client_account_id' => $account->id,
            'token' => $token,
            'method' => $method,
            'replace_payment_method_id' => $replacePaymentMethodId
                ? trim($replacePaymentMethodId)
                : null,
            'expires_at' => now()->addHours($hours),
            'created_by' => $actor ? $actor->id : null,
        ]);

        return [
            'id' => $link->id,
            'token' => $link->token,
            'method' => $link->method,
            'expires_at' => optional($link->expires_at)->toIso8601String(),
            'url' => url('/payment-method/'.$link->token),
        ];
    }

    public function findUsableLink(string $token): ?PaymentMethodLink
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $link = PaymentMethodLink::query()
            ->where('token', $token)
            ->with('clientAccount')
            ->first();
        if (! $link instanceof PaymentMethodLink || ! $link->isUsable()) {
            return null;
        }
        if (! $link->clientAccount instanceof ClientAccount) {
            return null;
        }

        return $link;
    }

    /**
     * @return array{client_secret: string, publishable_key: string, method: string}
     */
    public function createSetupIntentForLink(PaymentMethodLink $link): array
    {
        $key = $this->publishableKey();
        if ($key === '') {
            throw new \RuntimeException('Stripe publishable key is not configured.');
        }

        $account = $link->clientAccount;
        $stripe = $this->client();
        $customerId = $this->ensureStripeCustomer($stripe, $account);

        $types = $link->method === PaymentMethodLink::METHOD_ACH
            ? ['us_bank_account']
            : ['card'];

        try {
            $intent = $stripe->setupIntents->create([
                'customer' => $customerId,
                'payment_method_types' => $types,
                'usage' => 'off_session',
                'metadata' => [
                    'source' => 'crm_payment_method_link',
                    'client_account_id' => (string) $account->id,
                    'payment_method_link_id' => (string) $link->id,
                    'method' => $link->method,
                    'replace_payment_method_id' => (string) ($link->replace_payment_method_id ?? ''),
                ],
            ]);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage());
        }

        $secret = trim((string) ($intent->client_secret ?? ''));
        if ($secret === '') {
            throw new \RuntimeException('Could not create Stripe SetupIntent.');
        }

        return [
            'client_secret' => $secret,
            'publishable_key' => $key,
            'method' => $link->method,
        ];
    }

    public function markLinkConsumed(PaymentMethodLink $link, ?string $newPaymentMethodId = null): void
    {
        $replaceId = trim((string) ($link->replace_payment_method_id ?? ''));
        if ($replaceId !== '' && $newPaymentMethodId !== null && $newPaymentMethodId !== '' && $replaceId !== $newPaymentMethodId) {
            try {
                $this->detachPaymentMethod($link->clientAccount, $replaceId);
            } catch (\Throwable $e) {
                // Best-effort detach of the replaced method.
            }
        }

        $link->consumed_at = now();
        $link->save();
    }

    public function detachPaymentMethod(ClientAccount $account, string $paymentMethodId): void
    {
        $pmId = trim($paymentMethodId);
        if ($pmId === '') {
            throw new \RuntimeException('Invalid payment method.');
        }
        $customerId = trim((string) ($account->stripe_customer_id ?? ''));
        if ($customerId === '') {
            throw new \RuntimeException('Stripe Customer ID is missing for this account.');
        }

        $stripe = $this->client();
        try {
            $pm = $stripe->paymentMethods->retrieve($pmId, []);
            $pmArr = is_array($pm) ? $pm : $pm->toArray();
            $pmCustomer = trim((string) ($pmArr['customer'] ?? ''));
            if ($pmCustomer === '' || ! hash_equals($customerId, $pmCustomer)) {
                throw new \RuntimeException('Payment method does not belong to this account.');
            }
            $stripe->paymentMethods->detach($pmId, []);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentMethodDetail(ClientAccount $account, string $paymentMethodId): array
    {
        $pmId = trim($paymentMethodId);
        $customerId = trim((string) ($account->stripe_customer_id ?? ''));
        if ($customerId === '' || $pmId === '') {
            throw new \RuntimeException('Payment method not found.');
        }

        $stripe = $this->client();
        try {
            $pm = $stripe->paymentMethods->retrieve($pmId, []);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage());
        }

        $arr = is_array($pm) ? $pm : $pm->toArray();
        $pmCustomer = trim((string) ($arr['customer'] ?? ''));
        if ($pmCustomer === '' || ! hash_equals($customerId, $pmCustomer)) {
            throw new \RuntimeException('Payment method does not belong to this account.');
        }

        return $this->stripePayments->serializePaymentMethodDetail($arr);
    }

    public function pinMatches(string $pin): bool
    {
        $expected = (string) config('crm.payment_method_view_pin', '0912');

        return hash_equals($expected, trim($pin));
    }

    private function ensureStripeCustomer(StripeClient $stripe, ClientAccount $account): string
    {
        $customerId = trim((string) ($account->stripe_customer_id ?? ''));
        if ($customerId !== '') {
            return $customerId;
        }

        $email = filter_var((string) $account->email, FILTER_VALIDATE_EMAIL)
            ? (string) $account->email
            : null;
        $primary = $account->primaryAccountUser;
        if ($email === null && $primary instanceof User) {
            $email = filter_var((string) $primary->email, FILTER_VALIDATE_EMAIL)
                ? (string) $primary->email
                : null;
        }

        try {
            $created = $stripe->customers->create([
                'name' => (string) ($account->company_name ?: 'Save Rack Customer'),
                'email' => $email,
                'metadata' => [
                    'source' => 'crm_payment_method_link',
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
            throw new \RuntimeException('Stripe secret is not configured.');
        }

        return new StripeClient($secret);
    }
}
