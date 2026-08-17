<?php

namespace App\Support\Billing;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\PricingFeeTemplate;
use App\Models\WholesaleOrderFeeLine;
use Illuminate\Validation\ValidationException;

class WholesaleOrderFeeChargeCatalog
{
    /**
     * @var array<string, array{display_name: string, qty_label: string, keywords: list<string>, fee_groups: list<string>}>
     */
    private const DEFINITIONS = [
        WholesaleOrderFeeLine::TYPE_WHOLESALE_FULFILLMENT => [
            'display_name' => 'Wholesale Fulfillment',
            'qty_label' => 'Units',
            'keywords' => ['wholesalefulfillment', 'fulfillment'],
            'fee_groups' => [PricingFeeTemplate::CATEGORY_WHOLESALE],
        ],
        WholesaleOrderFeeLine::TYPE_MASTER_CARTON => [
            'display_name' => 'Master Carton',
            'qty_label' => 'Cartons',
            'keywords' => ['mastercarton'],
            'fee_groups' => [PricingFeeTemplate::CATEGORY_WHOLESALE],
        ],
        WholesaleOrderFeeLine::TYPE_PER_ITEM => [
            'display_name' => 'Per Item (if Master Carton not used)',
            'qty_label' => 'Items',
            'keywords' => ['peritem', 'ifmastercartonnotused'],
            'fee_groups' => [PricingFeeTemplate::CATEGORY_WHOLESALE],
        ],
        WholesaleOrderFeeLine::TYPE_PALLET_PREP => [
            'display_name' => 'Pallet Prep',
            'qty_label' => 'Pallets',
            'keywords' => ['palletprep'],
            'fee_groups' => [PricingFeeTemplate::CATEGORY_WHOLESALE],
        ],
        WholesaleOrderFeeLine::TYPE_LTL_PICKUP => [
            'display_name' => 'LTL Pickup',
            'qty_label' => 'Shipments',
            'keywords' => ['ltlpickup', 'ltl'],
            'fee_groups' => [PricingFeeTemplate::CATEGORY_WHOLESALE],
        ],
        WholesaleOrderFeeLine::TYPE_BARCODE_LABELING => [
            'display_name' => 'Barcode Labeling',
            'qty_label' => 'Labels',
            'keywords' => ['barcodelabeling', 'barcode'],
            'fee_groups' => [PricingFeeTemplate::CATEGORY_WHOLESALE],
        ],
        WholesaleOrderFeeLine::TYPE_BOX => [
            'display_name' => 'Box',
            'qty_label' => 'Boxes',
            'keywords' => ['box', 'boxes', 'boxprice'],
            'fee_groups' => [PricingFeeTemplate::CATEGORY_PACKAGING, PricingFeeTemplate::CATEGORY_WHOLESALE],
        ],
    ];

    /** @return list<string> */
    public static function lineTypes(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function isValidLineType(string $lineType): bool
    {
        return isset(self::DEFINITIONS[$lineType]);
    }

    public static function assertValidLineType(string $lineType): void
    {
        if (! self::isValidLineType($lineType)) {
            throw ValidationException::withMessages([
                'line_type' => ['Invalid wholesale fee line type.'],
            ]);
        }
    }

    public static function displayName(string $lineType): string
    {
        self::assertValidLineType($lineType);

        return self::DEFINITIONS[$lineType]['display_name'];
    }

    public static function qtyLabel(string $lineType): string
    {
        self::assertValidLineType($lineType);

        return self::DEFINITIONS[$lineType]['qty_label'];
    }

    public static function defaultUnitPriceCents(ClientAccount $account, string $lineType): int
    {
        self::assertValidLineType($lineType);
        $def = self::DEFINITIONS[$lineType];
        $account->loadMissing(['feeItems.pricingTemplate']);
        foreach ($account->feeItems as $fee) {
            if (! $fee instanceof ClientAccountFee) {
                continue;
            }
            if (self::feeMatchesLineType($fee, $def)) {
                return (int) round(((float) ($fee->amount ?? 0)) * 100);
            }
        }

        return 0;
    }

    /**
     * @param  array{display_name: string, qty_label: string, keywords: list<string>, fee_groups: list<string>}  $def
     */
    private static function feeMatchesLineType(ClientAccountFee $fee, array $def): bool
    {
        $group = (string) ($fee->fee_group ?? '');
        $templateCategory = '';
        $templateName = '';
        if ($fee->relationLoaded('pricingTemplate') && $fee->pricingTemplate !== null) {
            $templateCategory = (string) ($fee->pricingTemplate->category ?? '');
            $templateName = self::normalizeFeeKey((string) ($fee->pricingTemplate->name ?? ''));
        }

        $inGroup = in_array($group, $def['fee_groups'], true)
            || in_array($templateCategory, $def['fee_groups'], true);
        if (! $inGroup) {
            return false;
        }

        $label = self::normalizeFeeKey((string) ($fee->label ?? ''));
        $display = self::normalizeFeeKey($def['display_name']);
        if ($label !== '' && $label === $display) {
            return true;
        }
        if ($templateName !== '' && $templateName === $display) {
            return true;
        }

        foreach ($def['keywords'] as $keyword) {
            $key = self::normalizeFeeKey($keyword);
            if ($key === '') {
                continue;
            }
            if ($label !== '' && str_contains($label, $key)) {
                return true;
            }
            if ($templateName !== '' && str_contains($templateName, $key)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeFeeKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '', $value) ?? '';

        return $value;
    }

    /**
     * @return list<array{line_type: string, display_name: string, qty_label: string, default_unit_price_cents: int}>
     */
    public static function optionsForAccount(ClientAccount $account): array
    {
        $out = [];
        foreach (self::lineTypes() as $lineType) {
            $def = self::DEFINITIONS[$lineType];
            $out[] = [
                'line_type' => $lineType,
                'display_name' => $def['display_name'],
                'qty_label' => $def['qty_label'],
                'default_unit_price_cents' => self::defaultUnitPriceCents($account, $lineType),
            ];
        }

        return $out;
    }
}
