<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use App\Support\CrmUrls;
use App\Support\PortalOnboardingSectionRegistry;

class PortalOnboardingService
{
    public const BILLING_METHOD_CREDIT_CARD = 'credit_card';

    public const BILLING_METHOD_ACH = 'ach';

    public const BILLING_METHOD_MANUAL = 'manual';

    public const BILLING_STATUS_NOT_STARTED = 'not_started';

    public const BILLING_STATUS_PROCESSING = 'processing';

    public const BILLING_STATUS_COMPLETED = 'completed';

    public const BILLING_STATUS_FAILED = 'failed';

    /** @var ClientBrandLogoService */
    protected $brandLogos;

    public function __construct(ClientBrandLogoService $brandLogos)
    {
        $this->brandLogos = $brandLogos;
    }

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
            'preferences' => $this->serializePreferences($account),
            'brand_logo_url' => $this->brandLogos->publicUrl($account->brand_logo_path),
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
     * @param  array<string, mixed>  $input
     */
    public function savePreferenceSection(ClientAccount $account, string $sectionId, array $input): ClientAccount
    {
        if (! PortalOnboardingSectionRegistry::isValidSectionId($sectionId)) {
            throw new \InvalidArgumentException('Invalid onboarding section.');
        }

        $sanitized = PortalOnboardingSectionRegistry::sanitizeSectionInput($sectionId, $input);
        $prefs = $this->preferencesArray($account);
        $prefs[$sectionId] = array_merge($prefs[$sectionId] ?? [], $sanitized);
        $account->onboarding_preferences = $prefs;

        if ($sectionId === 'branding_information' && isset($sanitized['brand_name'])) {
            $account->brand_name = $sanitized['brand_name'];
        }

        $account->save();

        return $account->fresh();
    }

    public function isPreferenceSectionComplete(ClientAccount $account, string $sectionId): bool
    {
        if (! PortalOnboardingSectionRegistry::isValidSectionId($sectionId)) {
            return false;
        }

        $prefs = $this->preferencesArray($account);
        $sectionData = is_array($prefs[$sectionId] ?? null) ? $prefs[$sectionId] : [];

        return PortalOnboardingSectionRegistry::isSectionComplete($sectionId, $sectionData);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildTasks(User $user, ClientAccount $account): array
    {
        $accountComplete = $this->isAccountInformationComplete($user, $account);
        $billingUiStatus = $this->billingTaskUiStatus($account);

        $preferenceTasks = [
            [
                'id' => 'branding_information',
                'title' => 'Branding Information',
                'description' => 'Complete your branding setup by submitting your brand name, logo files, packaging preferences, and other important details.',
                'icon' => 'palette',
            ],
            [
                'id' => 'order_handling_preferences',
                'title' => 'Order Handling Preferences',
                'description' => 'Set how orders are processed and shipped, including shipment timing, warehouse routing, out-of-stock handling, address verification, and fraud review holds.',
                'icon' => 'tune',
            ],
            [
                'id' => 'packing_slips_preferences',
                'title' => 'Packing Slips Preferences',
                'description' => 'Choose how packing slips should be included with shipments, including branded documents, pricing visibility, and custom order notes.',
                'icon' => 'description',
            ],
            [
                'id' => 'shipping_carrier_preferences',
                'title' => 'Shipping Carrier Preferences',
                'description' => 'Choose your preferred shipping carriers, service levels, and delivery preferences for outbound shipments.',
                'icon' => 'local_shipping',
            ],
            [
                'id' => 'returns_handling_preferences',
                'title' => 'Returns Handling Preferences',
                'description' => 'Choose how returned orders and inventory should be handled once received at the fulfillment center.',
                'icon' => 'assignment_return',
            ],
            [
                'id' => 'inventory_sync',
                'title' => 'Inventory Sync',
                'description' => 'Choose how inventory quantities should sync between your store, sales channels, and warehouse inventory management system.',
                'icon' => 'sync',
            ],
        ];

        $tasks = [
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

        foreach ($preferenceTasks as $meta) {
            $id = $meta['id'];
            $complete = $id === 'order_handling_preferences'
                ? $this->isOrderHandlingGroupComplete($account)
                : $this->isPreferenceSectionComplete($account, $id);
            $tasks[] = [
                'id' => $id,
                'title' => $meta['title'],
                'description' => $meta['description'],
                'status' => $complete ? 'completed' : 'not_completed',
                'icon' => $meta['icon'],
            ];
        }

        return $tasks;
    }

    public function isOrderHandlingGroupComplete(ClientAccount $account): bool
    {
        $prefs = $this->preferencesArray($account);
        $merged = [];
        foreach (
            [
                'order_handling_preferences',
                'out_of_stock_handling',
                'address_verification',
                'fraud_review_holds',
            ] as $sectionId
        ) {
            $block = is_array($prefs[$sectionId] ?? null) ? $prefs[$sectionId] : [];
            $merged = array_merge($merged, $block);
        }

        return PortalOnboardingSectionRegistry::isSectionComplete('order_handling_preferences', $merged);
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

    /**
     * @return array<string, mixed>
     */
    private function serializePreferences(ClientAccount $account): array
    {
        return $this->preferencesArray($account);
    }

    /**
     * @return array<string, mixed>
     */
    private function preferencesArray(ClientAccount $account): array
    {
        $raw = $account->onboarding_preferences;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_array($raw)) {
            return $raw;
        }

        return [];
    }
}
