<?php

namespace App\Support;

final class InvoiceReviewReason
{
    public const OTHER_CHARGES = 'other_charges';

    public const FPP_NOT_MATCHING = 'fpp_not_matching';

    public const HIGH_POSTAGE = 'high_postage';

    public const MISSING_FEES = 'missing_fees';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::OTHER_CHARGES => 'Other Charges',
            self::FPP_NOT_MATCHING => 'FPP Not Matching',
            self::HIGH_POSTAGE => 'High Postage',
            self::MISSING_FEES => 'Missing Fees',
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::options());
    }

    public static function label(string $key): string
    {
        $options = self::options();
        $normalized = trim($key);

        return $options[$normalized] ?? $normalized;
    }
}
