<?php

namespace App\Support;

class PortalOnboardingSectionRegistry
{
    public const PREFERENCE_SECTION_IDS = [
        'branding_information',
        'order_handling_preferences',
        'out_of_stock_handling',
        'address_verification',
        'fraud_review_holds',
        'packing_slips_preferences',
        'shipping_carrier_preferences',
        'returns_handling_preferences',
        'inventory_sync',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function requiredFieldKeys(string $sectionId): array
    {
        return match ($sectionId) {
            'branding_information' => ['brand_name', 'branded_packaging', 'custom_inserts'],
            'order_handling_preferences' => ['order_shipment_timeline', 'multi_warehouse_routing'],
            'out_of_stock_handling' => ['out_of_stock_handling'],
            'address_verification' => ['address_verification'],
            'fraud_review_holds' => ['fraud_review_holds'],
            'packing_slips_preferences' => [
                'include_packing_slips',
                'include_brand_logo',
                'show_product_pricing',
                'include_support_phone',
                'include_note',
            ],
            'shipping_carrier_preferences' => [
                'domestic_carriers',
                'international_carriers',
                'international_customs_declaration',
            ],
            'returns_handling_preferences' => [
                'returned_items',
                'returned_item_disposal',
                'photos_of_returns',
            ],
            'inventory_sync' => ['real_time_inventory_sync'],
            default => [],
        };
    }

    /**
     * @return array<string, list<string>>
     */
    public static function allowedValues(): array
    {
        return [
            'branded_packaging' => ['no', 'yes', 'yes_will_provide'],
            'custom_inserts' => ['no', 'yes', 'yes_will_provide'],
            'order_shipment_timeline' => ['ship_as_ready', 'hold_specified', 'hold_until_approved'],
            'multi_warehouse_routing' => [
                'import_all_locations',
                'import_selected_locations',
                'import_save_rack_only',
            ],
            'out_of_stock_handling' => [
                'hold_until_back_in_stock',
                'allow_partial_shipment',
                'cancel_oos_ship_remaining',
                'require_manual_review',
            ],
            'address_verification' => ['hold_invalid', 'attempt_correction'],
            'fraud_review_holds' => ['hold_high_risk', 'ship_regardless', 'cancel_fraudulent'],
            'include_packing_slips' => ['yes', 'no'],
            'include_brand_logo' => ['yes', 'no'],
            'show_product_pricing' => ['yes', 'no'],
            'include_support_phone' => ['yes', 'no'],
            'include_note' => ['yes', 'no'],
            'domestic_carriers' => ['lowest_cost', 'store_requested', 'usps_preferred', 'ups_preferred'],
            'international_carriers' => [
                'lowest_cost_ddu',
                'lowest_cost_ddp',
                'usps_globalpost',
                'ups_canada_expedited',
                'dhl_express',
            ],
            'international_customs_declaration' => ['retail_value', 'custom_declared_value'],
            'returned_items' => [
                'restock_automatically',
                'dispose_non_restockable',
                'quarantine_before_disposal',
                'require_client_approval',
            ],
            'returned_item_disposal' => ['dispose', 'donate', 'ship_back', 'parts_repackaging'],
            'photos_of_returns' => ['no_photos', 'all_returns', 'damaged_only'],
            'real_time_inventory_sync' => [
                'enable_all_locations',
                'enable_save_rack_only',
                'disable',
            ],
        ];
    }

    public static function isValidSectionId(string $sectionId): bool
    {
        return in_array($sectionId, self::PREFERENCE_SECTION_IDS, true);
    }

    /**
     * @param  array<string, mixed>  $sectionData
     */
    public static function isSectionComplete(string $sectionId, array $sectionData): bool
    {
        foreach (self::requiredFieldKeys($sectionId) as $key) {
            $value = $sectionData[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
            $allowed = self::allowedValues()[$key] ?? null;
            if (is_array($allowed) && ! in_array($value, $allowed, true)) {
                return false;
            }
        }

        if ($sectionId === 'branding_information') {
            if (trim((string) ($sectionData['brand_name'] ?? '')) === '') {
                return false;
            }
        }

        if ($sectionId === 'packing_slips_preferences') {
            if (($sectionData['include_note'] ?? '') === 'yes') {
                if (trim((string) ($sectionData['packing_slip_note'] ?? '')) === '') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function sanitizeSectionInput(string $sectionId, array $input): array
    {
        $out = [];
        $keys = self::requiredFieldKeys($sectionId);
        if ($sectionId === 'branding_information') {
            $keys = array_merge($keys, []);
        }
        if ($sectionId === 'packing_slips_preferences') {
            $keys[] = 'packing_slip_note';
        }

        foreach ($keys as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
            if ($key === 'brand_name' || $key === 'packing_slip_note') {
                $out[$key] = $value;
                continue;
            }
            $allowed = self::allowedValues()[$key] ?? null;
            if (is_array($allowed) && in_array($value, $allowed, true)) {
                $out[$key] = $value;
            }
        }

        if ($sectionId === 'branding_information' && isset($input['brand_name'])) {
            $out['brand_name'] = trim((string) $input['brand_name']);
        }

        return $out;
    }
}
