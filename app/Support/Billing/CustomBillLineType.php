<?php

namespace App\Support\Billing;

/**
 * Custom bill line types (UI labels from legacy CRM).
 */
final class CustomBillLineType
{
    public const FULFILLMENT_SERVICE = 'Fulfillment Service';

    public const PACKAGING = 'Packaging';

    public const STORAGE = 'Storage';

    public const POSTAGE = 'Postage';

    public const NEW_PACKAGING = 'New Packaging';

    public const PACKAGING_MATERIAL = 'Packaging Material';

    public const PRODUCT = 'Product';

    public const ADMIN = 'Admin';

    public const OTHER = 'Other';

    public const CREDIT = 'Credit';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::FULFILLMENT_SERVICE,
            self::PACKAGING,
            self::STORAGE,
            self::POSTAGE,
            self::NEW_PACKAGING,
            self::PACKAGING_MATERIAL,
            self::PRODUCT,
            self::ADMIN,
            self::OTHER,
            self::CREDIT,
        ];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::all(), true);
    }

    public static function toInvoiceCategory(string $lineType): string
    {
        switch ($lineType) {
            case self::FULFILLMENT_SERVICE:
                return InvoiceLineCategory::FULFILLMENT;
            case self::PACKAGING:
            case self::NEW_PACKAGING:
            case self::PACKAGING_MATERIAL:
                return InvoiceLineCategory::PACKAGING;
            case self::STORAGE:
                return InvoiceLineCategory::STORAGE;
            case self::POSTAGE:
                return InvoiceLineCategory::POSTAGE;
            case self::PRODUCT:
                return InvoiceLineCategory::ON_DEMAND;
            case self::CREDIT:
                return InvoiceLineCategory::CREDITS;
            case self::ADMIN:
            case self::OTHER:
            default:
                return InvoiceLineCategory::AD_HOC;
        }
    }
}
