<?php

namespace App\Support\Billing;

/**
 * Invoice line categories for grouping, CSV routing, and staff UI sections.
 *
 * @see Product spec §3–4
 */
final class InvoiceLineCategory
{
    public const FULFILLMENT = 'fulfillment';

    public const POSTAGE = 'postage';

    public const PACKAGING = 'packaging';

    public const RETURNS = 'returns';

    public const AD_HOC = 'ad_hoc';

    public const STORAGE = 'storage';

    public const ON_DEMAND = 'on_demand';

    public const CREDITS = 'credits';

    public const OTHER = 'other';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::FULFILLMENT,
            self::POSTAGE,
            self::PACKAGING,
            self::RETURNS,
            self::AD_HOC,
            self::STORAGE,
            self::ON_DEMAND,
            self::CREDITS,
            self::OTHER,
        ];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::all(), true);
    }
}
