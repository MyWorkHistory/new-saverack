<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\ClientAccountReturn;
use Illuminate\Validation\ValidationException;

class ReturnFeeService
{
    /** @var array<string, list<string>> */
    private const LINE_LABEL_ALIASES = [
        ClientAccountFee::LINE_RETURNS_PROCESSING => [
            'returns processing',
            'returns (first item)',
            'return processing',
            'first item',
        ],
        ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS => [
            'returns additional items',
            'returns (additional items)',
            'additional items',
            'additional item',
        ],
        ClientAccountFee::LINE_RETURNS_NON_COMPLIANT => [
            'non-compliant return',
            'non compliant return',
            'noncompliant return',
            'non-compliant',
            'non compliant',
        ],
    ];

    /**
     * @return array{first_item: float|null, additional_item: float|null, non_compliant: float|null}
     */
    public function accountDefaults(ClientAccount $account): array
    {
        $account->loadMissing(['feeItems.pricingTemplate']);

        return [
            'first_item' => $this->amountForLine($account, ClientAccountFee::LINE_RETURNS_PROCESSING),
            'additional_item' => $this->amountForLine($account, ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS),
            'non_compliant' => $this->amountForLine($account, ClientAccountFee::LINE_RETURNS_NON_COMPLIANT),
        ];
    }

    public function seedReturnFees(ClientAccountReturn $return): void
    {
        $return->loadMissing('clientAccount.feeItems.pricingTemplate');
        if ($return->clientAccount === null) {
            return;
        }

        $defaults = $this->accountDefaults($return->clientAccount);
        $changed = false;
        if ($return->return_fee_first_item === null && $defaults['first_item'] !== null) {
            $return->return_fee_first_item = $defaults['first_item'];
            $changed = true;
        }
        if ($return->return_fee_additional_item === null && $defaults['additional_item'] !== null) {
            $return->return_fee_additional_item = $defaults['additional_item'];
            $changed = true;
        }
        if ($return->isNonCompliant()
            && $return->return_fee_non_compliant === null
            && $defaults['non_compliant'] !== null) {
            $return->return_fee_non_compliant = $defaults['non_compliant'];
            $changed = true;
        }
        if ($changed) {
            $return->saveQuietly();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeReturnFees(ClientAccountReturn $return): array
    {
        $payload = [
            'first_item' => $return->return_fee_first_item !== null ? (float) $return->return_fee_first_item : null,
            'additional_item' => $return->return_fee_additional_item !== null ? (float) $return->return_fee_additional_item : null,
            'locked' => $return->feesAreLocked(),
            'first_item_label' => 'Returns (First Item)',
            'additional_item_label' => 'Returns (Additional Items)',
        ];

        if ($return->isNonCompliant()) {
            $payload['non_compliant'] = $return->return_fee_non_compliant !== null
                ? (float) $return->return_fee_non_compliant
                : null;
            $payload['non_compliant_label'] = 'Non-Compliant Return';
        }

        return $payload;
    }

    public function updateReturnFees(
        ClientAccountReturn $return,
        ?float $firstItem,
        ?float $additionalItem,
        ?float $nonCompliant = null
    ): ClientAccountReturn {
        if ($return->feesAreLocked()) {
            throw ValidationException::withMessages([
                'fees' => ['Return fees are locked after processing.'],
            ]);
        }
        if ($firstItem !== null) {
            $return->return_fee_first_item = round($firstItem, 4);
        }
        if ($additionalItem !== null) {
            $return->return_fee_additional_item = round($additionalItem, 4);
        }
        if ($nonCompliant !== null && $return->isNonCompliant()) {
            $return->return_fee_non_compliant = round($nonCompliant, 4);
        }
        $return->save();

        return $return->fresh();
    }

    public function lockFees(ClientAccountReturn $return): void
    {
        if ($return->fees_locked_at === null) {
            $return->fees_locked_at = now();
            $return->saveQuietly();
        }
    }

    public function firstItemFeeAmount(ClientAccountReturn $return): float
    {
        return (float) ($return->return_fee_first_item ?? 0);
    }

    public function additionalItemFeeAmount(ClientAccountReturn $return): float
    {
        return (float) ($return->return_fee_additional_item ?? 0);
    }

    public function nonCompliantFeeAmount(ClientAccountReturn $return): float
    {
        return (float) ($return->return_fee_non_compliant ?? 0);
    }

    private function amountForLine(ClientAccount $account, string $lineCode): ?float
    {
        foreach ($account->feeItems as $fee) {
            if (! $fee instanceof ClientAccountFee) {
                continue;
            }
            if ($fee->fee_group !== ClientAccountFee::GROUP_RETURNS) {
                continue;
            }
            if (! $this->feeMatchesLineCode($fee, $lineCode)) {
                continue;
            }

            return $fee->amount !== null ? (float) $fee->amount : null;
        }

        return null;
    }

    private function feeMatchesLineCode(ClientAccountFee $fee, string $lineCode): bool
    {
        if ($fee->line_code === $lineCode) {
            return true;
        }

        $aliases = self::LINE_LABEL_ALIASES[$lineCode] ?? [];
        $normalizedAliases = array_map(fn (string $alias) => $this->normalizeFeeKey($alias), $aliases);
        $label = $this->normalizeFeeKey((string) ($fee->label ?? ''));
        if ($label !== '' && in_array($label, $normalizedAliases, true)) {
            return true;
        }

        if ($fee->relationLoaded('pricingTemplate') && $fee->pricingTemplate !== null) {
            $templateName = $this->normalizeFeeKey((string) ($fee->pricingTemplate->name ?? ''));
            if ($templateName !== '' && in_array($templateName, $normalizedAliases, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeFeeKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
