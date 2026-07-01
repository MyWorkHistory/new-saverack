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
        $labels = self::labelsForFields($fields);
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
