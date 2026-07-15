<?php

namespace App\Support\Billing;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\ReturnBill;
use Illuminate\Validation\ValidationException;

class ReturnBillChargeCatalog
{
  public const FIRST_ITEM_NAME = 'Returns (First Item)';

  public const ADDITIONAL_ITEMS_NAME = 'Returns (Additional Items)';

  public const ASSEMBLY_NAME = 'Returns Assembly';

  public const REPACKAGING_NAME = 'Returns Re-Packaging';

  public const DISPOSAL_NAME = 'Returns Disposal';

  public const NON_COMPLIANT_NAME = 'Non-Compliant Return';

  /** @var array<string, array{display_name: string, group_key: string, subtype: string, fee_line_code: string}> */
  private const DEFINITIONS = [
    ReturnBill::LINE_FIRST_ITEM => [
      'display_name' => self::FIRST_ITEM_NAME,
      'group_key' => 'returns:first',
      'subtype' => 'first',
      'fee_line_code' => ClientAccountFee::LINE_RETURNS_PROCESSING,
    ],
    ReturnBill::LINE_ADDITIONAL_ITEMS => [
      'display_name' => self::ADDITIONAL_ITEMS_NAME,
      'group_key' => 'returns:additional',
      'subtype' => 'additional',
      'fee_line_code' => ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS,
    ],
    ReturnBill::LINE_ASSEMBLY => [
      'display_name' => self::ASSEMBLY_NAME,
      'group_key' => 'returns:assembly',
      'subtype' => 'assembly',
      'fee_line_code' => ClientAccountFee::LINE_RETURNS_ASSEMBLY,
    ],
    ReturnBill::LINE_REPACKAGING => [
      'display_name' => self::REPACKAGING_NAME,
      'group_key' => 'returns:repackaging',
      'subtype' => 'repackaging',
      'fee_line_code' => ClientAccountFee::LINE_RETURNS_REPACKAGING,
    ],
    ReturnBill::LINE_DISPOSAL => [
      'display_name' => self::DISPOSAL_NAME,
      'group_key' => 'returns:disposal',
      'subtype' => 'disposal',
      'fee_line_code' => ClientAccountFee::LINE_RETURNS_DISPOSAL,
    ],
    ReturnBill::LINE_NON_COMPLIANT => [
      'display_name' => self::NON_COMPLIANT_NAME,
      'group_key' => 'returns:non_compliant',
      'subtype' => 'non_compliant',
      'fee_line_code' => ClientAccountFee::LINE_RETURNS_NON_COMPLIANT,
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
        'line_type' => ['Invalid return bill line type.'],
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
    $feeLineCode = self::DEFINITIONS[$lineType]['fee_line_code'];
    $displayName = self::DEFINITIONS[$lineType]['display_name'];
    $account->loadMissing(['feeItems.pricingTemplate']);
    foreach ($account->feeItems as $fee) {
      if (! $fee instanceof ClientAccountFee) {
        continue;
      }
      if ($fee->fee_group !== ClientAccountFee::GROUP_RETURNS) {
        continue;
      }
      if (self::feeMatchesLineCode($fee, $feeLineCode, $displayName)) {
        return (int) round(((float) ($fee->amount ?? 0)) * 100);
      }
    }

    return 0;
  }

  private static function feeMatchesLineCode(ClientAccountFee $fee, string $lineCode, string $displayName): bool
  {
    if ($fee->line_code === $lineCode) {
      return true;
    }

    $wanted = self::normalizeFeeKey($displayName);
    $label = self::normalizeFeeKey((string) ($fee->label ?? ''));
    if ($label !== '' && $label === $wanted) {
      return true;
    }

    if ($fee->relationLoaded('pricingTemplate') && $fee->pricingTemplate !== null) {
      $templateName = self::normalizeFeeKey((string) ($fee->pricingTemplate->name ?? ''));
      if ($templateName !== '' && $templateName === $wanted) {
        return true;
      }
    }

    return false;
  }

  private static function normalizeFeeKey(string $value): string
  {
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

    return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
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
