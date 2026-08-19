<?php

namespace App\Support\Billing;

use App\Models\AsnBill;
use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use Illuminate\Validation\ValidationException;

class AsnBillChargeCatalog
{
    public const QTY_BOXES = 'boxes';

    public const QTY_PALLETS = 'pallets';

    public const QTY_SKU = 'sku';

    public const QTY_NONE = 'none';

    /** @var array<string, array{display_name: string, group_key: string, subtype: string, fee_group: string, fee_line_code: string}> */
    private const DEFINITIONS = [
        AsnBill::LINE_RECEIVING_PER_BOX => [
            'display_name' => 'Receiving (Per Box)',
            'group_key' => 'asn:receiving_per_box',
            'subtype' => 'per_box',
            'fee_group' => ClientAccountFee::GROUP_RECEIVING,
            'fee_line_code' => 'per_box',
        ],
        AsnBill::LINE_RECEIVING_PER_PALLET => [
            'display_name' => 'Receiving (Per Pallet)',
            'group_key' => 'asn:receiving_per_pallet',
            'subtype' => 'per_pallet',
            'fee_group' => ClientAccountFee::GROUP_RECEIVING,
            'fee_line_code' => 'per_pallet',
        ],
        AsnBill::LINE_RECEIVING_PER_ITEM => [
            'display_name' => 'Receiving (Per SKU)',
            'group_key' => 'asn:receiving_per_item',
            'subtype' => 'per_item',
            'fee_group' => ClientAccountFee::GROUP_RECEIVING,
            'fee_line_code' => 'per_item',
        ],
        AsnBill::LINE_CUSTOM_HOURLY_WORK => [
            'display_name' => 'Custom Hourly Work',
            'group_key' => 'asn:custom_hourly_work',
            'subtype' => 'hourly',
            'fee_group' => ClientAccountFee::GROUP_CUSTOM_WORK,
            'fee_line_code' => 'hourly',
        ],
        AsnBill::LINE_NON_COMPLIANT => [
            'display_name' => 'Non-Compliant',
            'group_key' => 'asn:non_compliant',
            'subtype' => 'non_compliant',
            'fee_group' => ClientAccountFee::GROUP_RECEIVING,
            'fee_line_code' => 'non_compliant',
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

        return (bool) preg_match('/^fee_[0-9]+$/', $lineType);
    }

    public static function assertValidLineType(string $lineType): void
    {
        if (! self::isValidLineType($lineType)) {
            throw ValidationException::withMessages([
                'line_type' => ['Invalid ASN bill line type.'],
            ]);
        }
    }

    public static function displayName(string $lineType): string
    {
        if (isset(self::DEFINITIONS[$lineType])) {
            return self::DEFINITIONS[$lineType]['display_name'];
        }
        self::assertValidLineType($lineType);

        return 'Receiving Fee';
    }

    public static function groupKey(string $lineType): string
    {
        if (isset(self::DEFINITIONS[$lineType])) {
            return self::DEFINITIONS[$lineType]['group_key'];
        }
        self::assertValidLineType($lineType);

        return 'asn:'.$lineType;
    }

    public static function subtype(string $lineType): string
    {
        if (isset(self::DEFINITIONS[$lineType])) {
            return self::DEFINITIONS[$lineType]['subtype'];
        }
        self::assertValidLineType($lineType);

        return 'custom';
    }

    public static function defaultUnitPriceCents(ClientAccount $account, string $lineType): int
    {
        $option = self::optionForLineType($account, $lineType);
        if ($option !== null) {
            return (int) $option['default_unit_price_cents'];
        }
        if (! isset(self::DEFINITIONS[$lineType])) {
            return 0;
        }
        $def = self::DEFINITIONS[$lineType];
        $account->loadMissing(['feeItems.pricingTemplate']);
        foreach ($account->feeItems as $fee) {
            if (! $fee instanceof ClientAccountFee) {
                continue;
            }
            if ($fee->fee_group !== $def['fee_group'] && $fee->fee_group !== ClientAccountFee::GROUP_RECEIVING) {
                continue;
            }
            if (self::feeMatchesLineType($fee, $def)) {
                return (int) round(((float) ($fee->amount ?? 0)) * 100);
            }
        }

        return 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function optionForLineType(ClientAccount $account, string $lineType): ?array
    {
        foreach (self::optionsForAccount($account) as $option) {
            if (($option['line_type'] ?? '') === $lineType) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function optionForFeeId(ClientAccount $account, int $feeId): ?array
    {
        foreach (self::optionsForAccount($account) as $option) {
            if ((int) ($option['client_account_fee_id'] ?? 0) === $feeId) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Live receiving fees for the account (name + price). Used by the ASN fee modal.
     *
     * @return list<array<string, mixed>>
     */
    public static function optionsForAccount(ClientAccount $account): array
    {
        $account->loadMissing(['feeItems.pricingTemplate']);
        $usedLineTypes = [];
        $out = [];
        $fees = $account->feeItems->sortBy(function (ClientAccountFee $fee) {
            return ((int) $fee->sort_order) * 100000 + (int) $fee->id;
        })->values();

        foreach ($fees as $fee) {
            if (! $fee instanceof ClientAccountFee) {
                continue;
            }
            if ($fee->fee_group !== ClientAccountFee::GROUP_RECEIVING) {
                continue;
            }
            $qtyMode = self::qtyModeForFee($fee);
            $lineType = self::lineTypeForFee($fee, $usedLineTypes);
            $usedLineTypes[$lineType] = true;
            $label = trim((string) ($fee->label ?? ''));
            if ($label === '' && $fee->relationLoaded('pricingTemplate') && $fee->pricingTemplate) {
                $label = trim((string) ($fee->pricingTemplate->name ?? ''));
            }
            if ($label === '') {
                $label = self::displayName($lineType);
            }

            $out[] = [
                'line_type' => $lineType,
                'client_account_fee_id' => (int) $fee->id,
                'display_name' => $label,
                'group_key' => 'asn:fee:'.$fee->id,
                'subtype' => $qtyMode,
                'qty_mode' => $qtyMode,
                'qty_label' => self::qtyLabel($qtyMode),
                'autofill' => $qtyMode === self::QTY_BOXES || $qtyMode === self::QTY_PALLETS || $qtyMode === self::QTY_SKU,
                'default_unit_price_cents' => (int) round(((float) ($fee->amount ?? 0)) * 100),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, bool>  $usedLineTypes
     */
    private static function lineTypeForFee(ClientAccountFee $fee, array $usedLineTypes): string
    {
        $qtyMode = self::qtyModeForFee($fee);
        $preferred = null;
        if ($qtyMode === self::QTY_BOXES) {
            $preferred = AsnBill::LINE_RECEIVING_PER_BOX;
        } elseif ($qtyMode === self::QTY_PALLETS) {
            $preferred = AsnBill::LINE_RECEIVING_PER_PALLET;
        } elseif ($qtyMode === self::QTY_SKU) {
            $preferred = AsnBill::LINE_RECEIVING_PER_ITEM;
        } elseif (self::labelLooksHourly(self::feeSearchKey($fee))) {
            $preferred = AsnBill::LINE_CUSTOM_HOURLY_WORK;
        } elseif (self::labelLooksNonCompliant(self::feeSearchKey($fee))) {
            $preferred = AsnBill::LINE_NON_COMPLIANT;
        }

        if ($preferred !== null && empty($usedLineTypes[$preferred])) {
            return $preferred;
        }

        return 'fee_'.$fee->id;
    }

    public static function qtyModeForFee(ClientAccountFee $fee): string
    {
        $key = self::feeSearchKey($fee);
        if (self::labelLooksNonCompliant($key)) {
            return self::QTY_NONE;
        }
        if (self::labelLooksHourly($key)) {
            return self::QTY_NONE;
        }
        if (self::containsAny($key, ['persku', 'perskus'])) {
            return self::QTY_SKU;
        }
        if (self::containsAny($key, ['peritem', 'peritems']) && strpos($key, 'mastercarton') === false) {
            return self::QTY_SKU;
        }
        if (self::containsAny($key, ['perbox', 'perboxes'])) {
            return self::QTY_BOXES;
        }
        if (self::containsAny($key, ['perpallet', 'perpallets'])) {
            return self::QTY_PALLETS;
        }

        return self::QTY_NONE;
    }

    public static function qtyLabel(string $qtyMode): string
    {
        if ($qtyMode === self::QTY_BOXES) {
            return 'Boxes';
        }
        if ($qtyMode === self::QTY_PALLETS) {
            return 'Pallets';
        }
        if ($qtyMode === self::QTY_SKU) {
            return 'SKUs';
        }

        return 'Qty';
    }

    /**
     * @param  array{display_name: string, group_key: string, subtype: string, fee_group: string, fee_line_code: string}  $def
     */
    private static function feeMatchesLineType(ClientAccountFee $fee, array $def): bool
    {
        if ($fee->line_code === $def['fee_line_code']) {
            return true;
        }

        $label = self::normalizeFeeKey((string) ($fee->label ?? ''));
        if ($label !== '' && $label === self::normalizeFeeKey($def['display_name'])) {
            return true;
        }
        if ($label === 'receivingperitem' && $def['fee_line_code'] === 'per_item') {
            return true;
        }
        if ($label === 'receivingpersku' && $def['fee_line_code'] === 'per_item') {
            return true;
        }

        if ($fee->relationLoaded('pricingTemplate') && $fee->pricingTemplate !== null) {
            $templateName = self::normalizeFeeKey((string) ($fee->pricingTemplate->name ?? ''));
            if ($templateName !== '' && (
                $templateName === self::normalizeFeeKey($def['display_name'])
                || ($def['fee_line_code'] === 'per_item' && ($templateName === 'receivingperitem' || $templateName === 'receivingpersku'))
            )) {
                return true;
            }
        }

        return self::qtyModeForFee($fee) === self::qtyModeFromSubtype($def['subtype']);
    }

    private static function qtyModeFromSubtype(string $subtype): string
    {
        if ($subtype === 'per_box') {
            return self::QTY_BOXES;
        }
        if ($subtype === 'per_pallet') {
            return self::QTY_PALLETS;
        }
        if ($subtype === 'per_item') {
            return self::QTY_SKU;
        }

        return self::QTY_NONE;
    }

    private static function feeSearchKey(ClientAccountFee $fee): string
    {
        $parts = [
            (string) ($fee->line_code ?? ''),
            (string) ($fee->label ?? ''),
        ];
        if ($fee->relationLoaded('pricingTemplate') && $fee->pricingTemplate !== null) {
            $parts[] = (string) ($fee->pricingTemplate->name ?? '');
        }

        return self::normalizeFeeKey(implode(' ', $parts));
    }

    private static function labelLooksHourly(string $key): bool
    {
        return self::containsAny($key, ['hourly', 'perhour']);
    }

    private static function labelLooksNonCompliant(string $key): bool
    {
        return self::containsAny($key, ['noncompliant', 'noncompliance']);
    }

    /**
     * @param  list<string>  $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
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
}
