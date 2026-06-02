<?php

namespace App\Support;

final class ClientAccountBillingPreferences
{
    public const POSTAGE_SAVE_RACK_ALL = 'save_rack_all_postage';

    public const POSTAGE_CUSTOMER_USPS = 'customer_usps';

    public const POSTAGE_CUSTOMER_UPS = 'customer_ups';

    public const POSTAGE_CUSTOMER_FEDEX = 'customer_fedex';

    public const POSTAGE_CUSTOMER_MULTIPLE = 'customer_multiple_carriers';

    public const PACKAGING_SAVE_RACK_ALL = 'save_rack_all_packaging';

    public const PACKAGING_CUSTOMER_SOME = 'customer_some_packaging';

    public const PACKAGING_CUSTOMER_ALL = 'customer_all_packaging';

    /**
     * @return array<string, string>
     */
    public static function postageOptions(): array
    {
        return [
            self::POSTAGE_SAVE_RACK_ALL => 'Save Rack Provides All Postage',
            self::POSTAGE_CUSTOMER_USPS => 'Customer Provides USPS Account',
            self::POSTAGE_CUSTOMER_UPS => 'Customer Provides UPS Account',
            self::POSTAGE_CUSTOMER_FEDEX => 'Customer Provides Fedex Account',
            self::POSTAGE_CUSTOMER_MULTIPLE => 'Customer Provides Multiple Carrier Accounts',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function packagingOptions(): array
    {
        return [
            self::PACKAGING_SAVE_RACK_ALL => 'Save Rack Provides All Packaging Materials',
            self::PACKAGING_CUSTOMER_SOME => 'Customer Provides Some Packaging Materials',
            self::PACKAGING_CUSTOMER_ALL => 'Customer Provides All Packaging Materials',
        ];
    }

    /**
     * @return list<string>
     */
    public static function postageKeys(): array
    {
        return array_keys(self::postageOptions());
    }

    /**
     * @return list<string>
     */
    public static function packagingKeys(): array
    {
        return array_keys(self::packagingOptions());
    }

    public static function defaultPostageKey(): string
    {
        return self::POSTAGE_SAVE_RACK_ALL;
    }

    public static function defaultPackagingKey(): string
    {
        return self::PACKAGING_SAVE_RACK_ALL;
    }

    public static function postageLabel(?string $key): string
    {
        $options = self::postageOptions();
        $normalized = is_string($key) ? trim($key) : '';
        if ($normalized !== '' && isset($options[$normalized])) {
            return $options[$normalized];
        }

        return $options[self::defaultPostageKey()];
    }

    public static function packagingLabel(?string $key): string
    {
        $options = self::packagingOptions();
        $normalized = is_string($key) ? trim($key) : '';
        if ($normalized !== '' && isset($options[$normalized])) {
            return $options[$normalized];
        }

        return $options[self::defaultPackagingKey()];
    }

    public static function normalizePostageKey(?string $key): string
    {
        $normalized = is_string($key) ? trim($key) : '';
        if ($normalized !== '' && isset(self::postageOptions()[$normalized])) {
            return $normalized;
        }

        return self::defaultPostageKey();
    }

    public static function normalizePackagingKey(?string $key): string
    {
        $normalized = is_string($key) ? trim($key) : '';
        if ($normalized !== '' && isset(self::packagingOptions()[$normalized])) {
            return $normalized;
        }

        return self::defaultPackagingKey();
    }
}
