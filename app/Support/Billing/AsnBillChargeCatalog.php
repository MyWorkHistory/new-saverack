<?php

namespace App\Support\Billing;

use App\Models\AsnBill;
use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use Illuminate\Validation\ValidationException;

class AsnBillChargeCatalog
{
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
            'display_name' => 'Receiving (Per Item)',
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
        return isset(self::DEFINITIONS[$lineType]);
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
        self::assertValidLineType($lineType);

        return self::DEFINITIONS[$lineType]['display_name'];
    }

    public static function groupKey(string $lineType): string
    {
        self::assertValidLineType($lineType);

        return self::DEFINITIONS[$lineType]['group_key'];
    }

    public static function subtype(string $lineType): string
    {
        self::assertValidLineType($lineType);

        return self::DEFINITIONS[$lineType]['subtype'];
    }

    public static function defaultUnitPriceCents(ClientAccount $account, string $lineType): int
    {
        self::assertValidLineType($lineType);
        $def = self::DEFINITIONS[$lineType];
        $account->loadMissing('feeItems');
        foreach ($account->feeItems as $fee) {
            if (! $fee instanceof ClientAccountFee) {
                continue;
            }
            if ($fee->fee_group !== $def['fee_group']) {
                continue;
            }
            if ($fee->line_code === $def['fee_line_code']) {
                return (int) round(((float) ($fee->amount ?? 0)) * 100);
            }
        }

        return 0;
    }

    /**
     * @return list<array{line_type: string, display_name: string, group_key: string, subtype: string, default_unit_price_cents: int}>
     */
    public static function optionsForAccount(ClientAccount $account): array
    {
        $out = [];
        foreach (self::lineTypes() as $lineType) {
            $def = self::DEFINITIONS[$lineType];
            $out[] = [
                'line_type' => $lineType,
                'display_name' => $def['display_name'],
                'group_key' => $def['group_key'],
                'subtype' => $def['subtype'],
                'default_unit_price_cents' => self::defaultUnitPriceCents($account, $lineType),
            ];
        }

        return $out;
    }
}
