<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use App\Support\CrmUrls;

class PortalOnboardingService
{
    public const BILLING_METHOD_CREDIT_CARD = 'credit_card';

    public const BILLING_METHOD_ACH = 'ach';

    public const BILLING_METHOD_MANUAL = 'manual';

    public const BILLING_STATUS_NOT_STARTED = 'not_started';

    public const BILLING_STATUS_PROCESSING = 'processing';

    public const BILLING_STATUS_COMPLETED = 'completed';

    public const BILLING_STATUS_FAILED = 'failed';

    public function isAccountInformationComplete(User $user, ClientAccount $account): bool
    {
        $fields = [
            trim((string) $account->company_name),
            trim((string) $user->name),
            trim((string) $user->email),
            trim((string) $account->phone),
            trim((string) $account->street),
            trim((string) $account->city),
            trim((string) $account->state),
            trim((string) $account->zip),
            trim((string) $account->country),
        ];

        foreach ($fields as $value) {
            if ($value === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildOnboardingPayload(User $user, ClientAccount $account): array
    {
        $profile = $this->serializeProfile($user, $account);
        $tasks = $this->buildTasks($user, $account);
        $completedCount = 0;
        foreach ($tasks as $task) {
            if (($task['status'] ?? '') === 'completed') {
                $completedCount++;
            }
        }

        return [
            'client_account_status' => (string) $account->status,
            'profile' => $profile,
            'tasks' => $tasks,
            'progress' => [
                'total' => count($tasks),
                'completed' => $completedCount,
                'remaining' => count($tasks) - $completedCount,
            ],
            'manual_payment_instructions' => config('crm.portal_manual_payment_instructions'),
            'stripe_payment_link_url' => config('crm.stripe_onboarding_payment_link_url'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildTasks(User $user, ClientAccount $account): array
    {
        $accountComplete = $this->isAccountInformationComplete($user, $account);
        $billingUiStatus = $this->billingTaskUiStatus($account);

        return [
            [
                'id' => 'account_information',
                'title' => 'Add Account Information',
                'description' => 'Complete your company profile, contact details, business address, and primary account contacts.',
                'status' => $accountComplete ? 'completed' : 'not_completed',
                'icon' => 'account',
            ],
            [
                'id' => 'billing_information',
                'title' => 'Add Billing Information',
                'description' => 'Add your payment method and billing details so invoices and fulfillment charges can be processed without delays.',
                'status' => $billingUiStatus,
                'icon' => 'billing',
            ],
        ];
    }

    private function billingTaskUiStatus(ClientAccount $account): string
    {
        $raw = trim((string) ($account->onboarding_billing_status ?? ''));
        if ($raw === self::BILLING_STATUS_COMPLETED) {
            return 'completed';
        }
        if ($raw === self::BILLING_STATUS_PROCESSING) {
            return 'processing';
        }

        return 'not_completed';
    }

    public function completeManualBilling(ClientAccount $account): ClientAccount
    {
        $account->default_payment_type = 'Manual';
        $account->onboarding_billing_method = self::BILLING_METHOD_MANUAL;
        $account->onboarding_billing_status = self::BILLING_STATUS_COMPLETED;
        $account->save();

        return $account->fresh();
    }

    public function markBillingStripeCheckoutStarted(ClientAccount $account, string $method): ClientAccount
    {
        $account->onboarding_billing_method = $method;
        if ($method === self::BILLING_METHOD_ACH) {
            $account->onboarding_billing_status = self::BILLING_STATUS_PROCESSING;
        } else {
            $account->onboarding_billing_status = self::BILLING_STATUS_NOT_STARTED;
        }
        $account->save();

        return $account->fresh();
    }

    public function welcomeBillingReturnUrl(string $queryFlag): string
    {
        $base = CrmUrls::frontendBase().'/users/welcome';

        return $base.'?billing='.$queryFlag;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeProfile(User $user, ClientAccount $account): array
    {
        $contactName = trim(implode(' ', array_filter([
            trim((string) $account->contact_first_name),
            trim((string) $account->contact_last_name),
        ])));

        return [
            'user_id' => $user->id,
            'client_account_id' => $account->id,
            'name' => $user->name,
            'email' => $user->email,
            'company_name' => $account->company_name,
            'contact_full_name' => $contactName !== '' ? $contactName : $user->name,
            'phone' => $account->phone,
            'street' => $account->street,
            'city' => $account->city,
            'state' => $account->state,
            'zip' => $account->zip,
            'country' => $account->country,
            'account_information_complete' => $this->isAccountInformationComplete($user, $account),
            'onboarding_billing_method' => $account->onboarding_billing_method,
            'onboarding_billing_status' => $account->onboarding_billing_status,
        ];
    }
}
