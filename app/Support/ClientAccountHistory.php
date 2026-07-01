<?php

namespace App\Support;

final class ClientAccountHistory
{
    /** @var array<string, string> */
    public const FIELD_LABELS = [
        'company_name' => 'Company',
        'brand_name' => 'Brand name',
        'email' => 'Email',
        'phone' => 'Phone',
        'status' => 'Status',
        'contact_first_name' => 'Contact first name',
        'contact_last_name' => 'Contact last name',
        'website' => 'Website',
        'street' => 'Street',
        'city' => 'City',
        'state' => 'State',
        'zip' => 'ZIP',
        'country' => 'Country',
        'notes' => 'Notes',
        'account_manager_id' => 'Account manager',
        'default_payment_type' => 'Default payment type',
        'payment_terms_days' => 'Payment terms',
        'cc_fee_percent' => 'Credit card fee',
        'postage_option' => 'Postage',
        'packaging_option' => 'Packaging',
        'contract_date' => 'Contract date',
        'notify_email' => 'Notify email',
        'notification_email' => 'Notification email',
        'telegram_handle' => 'Telegram',
        'whatsapp_e164' => 'WhatsApp',
        'slack_channel' => 'Slack channel',
        'in_house_slack' => 'In-House Slack',
        'whatsapp_api_id' => 'WhatsApp API ID',
        'stripe_customer_id' => 'Stripe customer ID',
        'shiphero_customer_account_id' => 'ShipHero customer account ID',
        'fees' => 'Fees',
    ];

    /** @var array<string, string> */
    private const SECTION_SUMMARIES = [
        'account' => 'Personal Information',
        'address' => 'Address',
        'left' => 'Settings',
        'settings' => 'Settings',
        'billing' => 'Billing',
        'payment' => 'Billing',
    ];

    /** @var array<string, list<string>> */
    private const FIELD_CATEGORIES = [
        'personal' => [
            'company_name',
            'brand_name',
            'website',
            'contact_first_name',
            'contact_last_name',
            'email',
            'phone',
        ],
        'address' => [
            'street',
            'city',
            'state',
            'zip',
            'country',
        ],
        'settings' => [
            'account_manager_id',
            'notify_email',
            'notification_email',
            'telegram_handle',
            'whatsapp_e164',
            'slack_channel',
            'in_house_slack',
            'whatsapp_api_id',
            'stripe_customer_id',
            'shiphero_customer_account_id',
        ],
        'billing' => [
            'default_payment_type',
            'payment_terms_days',
            'cc_fee_percent',
            'postage_option',
            'packaging_option',
            'contract_date',
        ],
        'fees' => ['fees'],
        'status' => ['status'],
        'notes' => ['notes'],
    ];

    /** @var array<string, string> */
    private const CATEGORY_LABELS = [
        'personal' => 'Personal Information',
        'address' => 'Address',
        'settings' => 'Settings',
        'billing' => 'Billing',
        'fees' => 'Fees',
        'status' => 'Status',
        'notes' => 'Notes',
    ];

    /**
     * @param  list<string>  $fields
     */
    public static function summarizeUpdate(array $fields, ?string $historySection = null): string
    {
        $section = trim((string) ($historySection ?? ''));
        if ($section !== '' && isset(self::SECTION_SUMMARIES[$section])) {
            return self::SECTION_SUMMARIES[$section];
        }

        return self::summarizeFields($fields);
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function labelsForFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $key = (string) $field;
            if ($key === '') {
                continue;
            }
            $out[] = self::FIELD_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
        }

        return $out;
    }

    /**
     * @param  list<string>  $fields
     */
    public static function summarizeFields(array $fields): string
    {
        $normalized = [];
        foreach ($fields as $field) {
            $key = trim((string) $field);
            if ($key !== '') {
                $normalized[] = $key;
            }
        }

        if ($normalized === []) {
            return 'account details';
        }

        $categories = self::categoriesForFields($normalized);
        if ($categories !== []) {
            $labels = [];
            foreach ($categories as $category) {
                $labels[] = self::CATEGORY_LABELS[$category] ?? ucfirst(str_replace('_', ' ', $category));
            }

            return self::joinLabels($labels);
        }

        $labels = self::labelsForFields($normalized);
        if (count($labels) === 1) {
            return $labels[0];
        }

        return self::joinLabels($labels);
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private static function categoriesForFields(array $fields): array
    {
        $fieldToCategory = [];
        foreach (self::FIELD_CATEGORIES as $category => $keys) {
            foreach ($keys as $key) {
                $fieldToCategory[$key] = $category;
            }
        }

        $seen = [];
        $ordered = [];
        foreach ($fields as $field) {
            $category = $fieldToCategory[$field] ?? null;
            if ($category === null || isset($seen[$category])) {
                continue;
            }
            $seen[$category] = true;
            $ordered[] = $category;
        }

        if (count($ordered) !== count($fields)) {
            foreach ($fields as $field) {
                if (! isset($fieldToCategory[$field])) {
                    return [];
                }
            }
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $labels
     */
    private static function joinLabels(array $labels): string
    {
        if ($labels === []) {
            return 'account details';
        }
        if (count($labels) === 1) {
            return $labels[0];
        }
        if (count($labels) === 2) {
            return $labels[0].' and '.$labels[1];
        }

        return implode(', ', array_slice($labels, 0, -1)).', and '.$labels[count($labels) - 1];
    }
}
