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
        if (isset(self::DEFINITIONS[$lineType])) {
            return true;
        }

        return (bool) preg_match('/^(fee_|custom_)[a-zA-Z0-9_]+$/', $lineType);
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
        if (isset(self::DEFINITIONS[$lineType])) {
            return self::DEFINITIONS[$lineType]['display_name'];
        }
        self::assertValidLineType($lineType);

        return 'Wholesale Fee';
    }

    public static function qtyLabel(string $lineType): string
    {
        if (isset(self::DEFINITIONS[$lineType])) {
            return self::DEFINITIONS[$lineType]['qty_label'];
        }

        return 'Qty';
    }

    public static function defaultUnitPriceCents(ClientAccount $account, string $lineType): int
    {
        foreach (self::optionsForAccount($account) as $option) {
            if (($option['line_type'] ?? '') === $lineType) {
                return (int) $option['default_unit_price_cents'];
            }
        }

        return self::unitPriceCentsFromDefinitions($account, $lineType);
    }

    private static function unitPriceCentsFromDefinitions(ClientAccount $account, string $lineType): int
    {
        if (! isset(self::DEFINITIONS[$lineType])) {
            return 0;
        }
        $def = self::DEFINITIONS[$lineType];
        $account->loadMissing(['feeItems.pricingTemplate']);
        foreach ($account->feeItems as $fee) {
            if (! $fee instanceof ClientAccountFee) {
                continue;
            }
            if (self::feeMatchesLineType($fee, $def)) {
                return self::feeAmountCents($fee);
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
            if ($label !== '' && strpos($label, $key) !== false) {
                return true;
            }
            if ($templateName !== '' && strpos($templateName, $key) !== false) {
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
     * Live wholesale fees for the account. Falls back to standard defs when none exist.
     *
     * @return list<array<string, mixed>>
     */
    public static function optionsForAccount(ClientAccount $account): array
    {
        $account->unsetRelation('feeItems');
        $account->load(['feeItems.pricingTemplate']);
        $usedLineTypes = [];
        $out = [];
        $fees = $account->feeItems->sortBy(function ($fee) {
            if (! $fee instanceof ClientAccountFee) {
                return 0;
            }

            return ((int) $fee->sort_order) * 100000 + (int) $fee->id;
        })->values();

        foreach ($fees as $fee) {
            if (! $fee instanceof ClientAccountFee) {
                continue;
            }
            if (! self::isWholesaleModalFee($fee)) {
                continue;
            }
            $lineType = self::lineTypeForFee($fee, $usedLineTypes);
            $usedLineTypes[$lineType] = true;
            $label = self::feeDisplayLabel($fee, $lineType);
            $qtyLabel = self::qtyLabelForFee($fee, $lineType);

            $out[] = [
                'line_type' => $lineType,
                'client_account_fee_id' => (int) $fee->id,
                'display_name' => $label,
                'qty_label' => $qtyLabel,
                'source' => WholesaleOrderFeeLine::SOURCE_WHOLESALE,
                'default_unit_price_cents' => self::feeAmountCents($fee),
            ];
        }

        if ($out !== []) {
            return $out;
        }

        return self::fallbackStandardOptions($account);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function packagingOptionsForAccount(ClientAccount $account): array
    {
        $account->unsetRelation('feeItems');
        $account->load(['feeItems.pricingTemplate']);
        $out = [];
        $seenFeeIds = [];
        $fees = $account->feeItems->sortBy(function ($fee) {
            if (! $fee instanceof ClientAccountFee) {
                return 0;
            }

            return ((int) $fee->sort_order) * 100000 + (int) $fee->id;
        })->values();

        foreach ($fees as $fee) {
            if (! $fee instanceof ClientAccountFee) {
                continue;
            }
            if (! self::isPackagingDropdownFee($fee)) {
                continue;
            }
            $feeId = (int) $fee->id;
            if (isset($seenFeeIds[$feeId])) {
                continue;
            }
            $seenFeeIds[$feeId] = true;
            $label = self::feeDisplayLabel($fee, 'fee_'.$fee->id);
            $out[] = [
                'line_type' => 'fee_'.$fee->id,
                'client_account_fee_id' => $feeId,
                'display_name' => $label,
                'qty_label' => self::qtyLabelForFee($fee, 'fee_'.$fee->id),
                'source' => WholesaleOrderFeeLine::SOURCE_PACKAGING,
                'default_unit_price_cents' => self::feeAmountCents($fee),
            ];
        }

        return $out;
    }

    /**
     * Account Fees-tab options used for box billing (packaging + wholesale only).
     * Uses the account fee row amount/label — not Settings template defaults.
     *
     * @return list<array<string, mixed>>
     */
    public static function accountBoxBillingFeeOptions(ClientAccount $account): array
    {
        $account->unsetRelation('feeItems');
        $account->load(['feeItems']);
        $out = [];
        $seen = [];

        foreach ($account->feeItems as $fee) {
            if (! $fee instanceof ClientAccountFee) {
                continue;
            }
            $group = strtolower(trim((string) ($fee->fee_group ?? '')));
            if ($group !== PricingFeeTemplate::CATEGORY_PACKAGING
                && $group !== PricingFeeTemplate::CATEGORY_WHOLESALE) {
                continue;
            }
            $feeId = (int) $fee->id;
            if ($feeId <= 0 || isset($seen[$feeId])) {
                continue;
            }
            $label = trim((string) ($fee->label ?? ''));
            if ($label === '') {
                continue;
            }
            $seen[$feeId] = true;
            $out[] = [
                'line_type' => 'fee_'.$feeId,
                'client_account_fee_id' => $feeId,
                'display_name' => $label,
                'qty_label' => 'Boxes',
                'source' => WholesaleOrderFeeLine::SOURCE_PACKAGING,
                'default_unit_price_cents' => self::feeAmountCents($fee),
                'fee_group' => $group,
            ];
        }

        return $out;
    }

    /**
     * Account fee amount from the Fees tab only (ignores Settings template defaults).
     */
    public static function feeAmountCents(ClientAccountFee $fee): int
    {
        $amount = $fee->amount;
        if ($amount === null || $amount === '') {
            return 0;
        }
        if (! is_numeric($amount)) {
            return 0;
        }

        return (int) round(max(0, (float) $amount) * 100);
    }

    /**
     * Packaging category fees, plus Box-style fees (even if stored under wholesale).
     */
    private static function isPackagingDropdownFee(ClientAccountFee $fee): bool
    {
        if (self::isPackagingFee($fee)) {
            return true;
        }

        // Account fees named/templated as Box often live under wholesale category.
        $boxDef = self::DEFINITIONS[WholesaleOrderFeeLine::TYPE_BOX] ?? null;
        if ($boxDef !== null && self::feeMatchesLineType($fee, $boxDef)) {
            return true;
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fallbackStandardOptions(ClientAccount $account): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $lineType => $def) {
            $out[] = [
                'line_type' => $lineType,
                'client_account_fee_id' => null,
                'display_name' => $def['display_name'],
                'qty_label' => $def['qty_label'],
                'source' => WholesaleOrderFeeLine::SOURCE_WHOLESALE,
                'default_unit_price_cents' => self::unitPriceCentsFromDefinitions($account, $lineType),
            ];
        }

        return $out;
    }

    private static function isWholesaleModalFee(ClientAccountFee $fee): bool
    {
        $group = strtolower(trim((string) ($fee->fee_group ?? '')));
        if ($group === PricingFeeTemplate::CATEGORY_WHOLESALE) {
            return true;
        }
        if ($fee->relationLoaded('pricingTemplate') && $fee->pricingTemplate !== null) {
            $category = strtolower(trim((string) ($fee->pricingTemplate->category ?? '')));
            if ($category === PricingFeeTemplate::CATEGORY_WHOLESALE) {
                return true;
            }
        }

        return false;
    }

    private static function isPackagingFee(ClientAccountFee $fee): bool
    {
        $group = strtolower(trim((string) ($fee->fee_group ?? '')));
        if ($group === PricingFeeTemplate::CATEGORY_PACKAGING) {
            return true;
        }
        if ($fee->relationLoaded('pricingTemplate') && $fee->pricingTemplate !== null) {
            $category = strtolower(trim((string) ($fee->pricingTemplate->category ?? '')));
            if ($category === PricingFeeTemplate::CATEGORY_PACKAGING) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, bool>  $usedLineTypes
     */
    private static function lineTypeForFee(ClientAccountFee $fee, array $usedLineTypes): string
    {
        foreach (self::DEFINITIONS as $lineType => $def) {
            if (! empty($usedLineTypes[$lineType])) {
                continue;
            }
            if (self::feeMatchesLineType($fee, $def)) {
                return $lineType;
            }
        }

        return 'fee_'.$fee->id;
    }

    private static function feeDisplayLabel(ClientAccountFee $fee, string $lineType): string
    {
        $label = trim((string) ($fee->label ?? ''));
        if ($label === '' && $fee->relationLoaded('pricingTemplate') && $fee->pricingTemplate) {
            $label = trim((string) ($fee->pricingTemplate->name ?? ''));
        }
        if ($label === '') {
            $label = self::displayName($lineType);
        }

        return $label;
    }

    private static function qtyLabelForFee(ClientAccountFee $fee, string $lineType): string
    {
        if (isset(self::DEFINITIONS[$lineType])) {
            return self::DEFINITIONS[$lineType]['qty_label'];
        }
        $key = self::normalizeFeeKey(self::feeDisplayLabel($fee, $lineType));
        if (strpos($key, 'pallet') !== false) {
            return 'Pallets';
        }
        if (strpos($key, 'carton') !== false) {
            return 'Cartons';
        }
        if (strpos($key, 'label') !== false) {
            return 'Labels';
        }
        if (strpos($key, 'box') !== false) {
            return 'Boxes';
        }
        if (strpos($key, 'item') !== false || strpos($key, 'unit') !== false) {
            return 'Units';
        }

        return 'Qty';
    }
}
