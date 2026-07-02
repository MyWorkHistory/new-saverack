<?php

namespace App\Support;

use App\Models\ClientAccount;
use Carbon\Carbon;

final class ClientAccountBillingPreferences
{
    public const DEFAULT_PAYMENT_TERMS_DAYS = 1;

    public const MAX_PAYMENT_TERMS_DAYS = 365;

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

    public static function normalizePaymentTermsDays(?int $days): int
    {
        if ($days === null || $days < 1) {
            return self::DEFAULT_PAYMENT_TERMS_DAYS;
        }

        return min($days, self::MAX_PAYMENT_TERMS_DAYS);
    }

    public static function invoiceDueDate(ClientAccount $account, ?Carbon $baseDate = null): Carbon
    {
        $base = ($baseDate ?? now())->copy()->startOfDay();
        $days = self::normalizePaymentTermsDays($account->payment_terms_days);

        return $base->copy()->addDays($days);
    }

    public static function paymentTermsLabel(?int $days): string
    {
        return 'Net '.self::normalizePaymentTermsDays($days);
    }

    public static function paymentTermsLabelForAccount(ClientAccount $account): string
    {
        return self::paymentTermsLabel($account->payment_terms_days);
    }

    /**
     * Invoice-specific payment terms when set; null when the invoice should inherit account defaults.
     */
    public static function invoicePaymentTermsOverride(?string $invoiceTerms, ?ClientAccount $account): ?string
    {
        $trimmed = is_string($invoiceTerms) ? trim($invoiceTerms) : '';
        if ($trimmed === '') {
            return null;
        }

        $accountLabel = $account !== null
            ? self::paymentTermsLabelForAccount($account)
            : self::paymentTermsLabel(self::DEFAULT_PAYMENT_TERMS_DAYS);

        if (strcasecmp($trimmed, $accountLabel) === 0) {
            return null;
        }

        $legacyDefault = self::paymentTermsLabel(self::DEFAULT_PAYMENT_TERMS_DAYS);
        if ($account !== null
            && strcasecmp($trimmed, $legacyDefault) === 0
            && self::normalizePaymentTermsDays($account->payment_terms_days) !== self::DEFAULT_PAYMENT_TERMS_DAYS) {
            return null;
        }

        return $trimmed;
    }

    public static function effectivePaymentTerms(?string $invoiceTerms, ?ClientAccount $account): string
    {
        $override = self::invoicePaymentTermsOverride($invoiceTerms, $account);
        if ($override !== null) {
            return $override;
        }
        if ($account !== null) {
            return self::paymentTermsLabelForAccount($account);
        }

        return self::paymentTermsLabel(self::DEFAULT_PAYMENT_TERMS_DAYS);
    }

    public static function invoicePaymentTermsOverridden(?string $invoiceTerms, ?ClientAccount $account = null): bool
    {
        return self::invoicePaymentTermsOverride($invoiceTerms, $account) !== null;
    }
}
