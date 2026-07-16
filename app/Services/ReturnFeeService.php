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
            'returns first item',
            'return processing',
            'first item',
            'returns first',
        ],
        ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS => [
            'returns additional items',
            'returns additional item',
            'additional items',
            'additional item',
            'returns additional',
        ],
        ClientAccountFee::LINE_RETURNS_NON_COMPLIANT => [
            'non compliant return',
            'noncompliant return',
            'non compliant',
            'noncompliant',
        ],
    ];

    /**
     * @return array{first_item: float|null, additional_item: float|null, non_compliant: float|null}
     */
    public function accountDefaults(ClientAccount $account): array
    {
        $account->unsetRelation('feeItems');
        $account->load(['feeItems.pricingTemplate']);

        return [
            'first_item' => $this->amountForLine($account, ClientAccountFee::LINE_RETURNS_PROCESSING),
            'additional_item' => $this->amountForLine($account, ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS),
            'non_compliant' => $this->amountForLine($account, ClientAccountFee::LINE_RETURNS_NON_COMPLIANT),
        ];
    }

    public function seedReturnFees(ClientAccountReturn $return): void
    {
        if ($return->feesAreLocked()) {
            return;
        }

        $defaults = $this->defaultsForReturn($return);
        $changed = false;

        if ($this->shouldApplyDefault($return->return_fee_first_item, $defaults['first_item'])) {
            $return->return_fee_first_item = $defaults['first_item'];
            $changed = true;
        }
        if ($this->shouldApplyDefault($return->return_fee_additional_item, $defaults['additional_item'])) {
            $return->return_fee_additional_item = $defaults['additional_item'];
            $changed = true;
        }
        if ($return->isNonCompliant()
            && $this->shouldApplyDefault($return->return_fee_non_compliant, $defaults['non_compliant'])) {
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
        if (! $return->feesAreLocked()) {
            $this->seedReturnFees($return);
            $return->refresh();
        }

        $defaults = $this->defaultsForReturn($return);

        $first = $return->return_fee_first_item !== null
            ? (float) $return->return_fee_first_item
            : $defaults['first_item'];
        $additional = $return->return_fee_additional_item !== null
            ? (float) $return->return_fee_additional_item
            : $defaults['additional_item'];

        // Unlocked placeholder zeros should still surface the live account fee.
        if (! $return->feesAreLocked()) {
            if (($first === null || $first == 0.0) && $defaults['first_item'] !== null && $defaults['first_item'] > 0) {
                $first = $defaults['first_item'];
            }
            if (($additional === null || $additional == 0.0) && $defaults['additional_item'] !== null && $defaults['additional_item'] > 0) {
                $additional = $defaults['additional_item'];
            }
            $this->persistResolvedFees($return, $first, $additional, null);
        }

        $payload = [
            'first_item' => $first,
            'additional_item' => $additional,
            'locked' => $return->feesAreLocked(),
            'first_item_label' => 'Returns (First Item)',
            'additional_item_label' => 'Returns (Additional Items)',
        ];

        if ($return->isNonCompliant()) {
            $nonCompliant = $return->return_fee_non_compliant !== null
                ? (float) $return->return_fee_non_compliant
                : $defaults['non_compliant'];
            if (! $return->feesAreLocked()
                && ($nonCompliant === null || $nonCompliant == 0.0)
                && $defaults['non_compliant'] !== null
                && $defaults['non_compliant'] > 0) {
                $nonCompliant = $defaults['non_compliant'];
            }
            if (! $return->feesAreLocked()) {
                $this->persistResolvedFees($return, null, null, $nonCompliant);
            }
            $payload['non_compliant'] = $nonCompliant;
            $payload['non_compliant_label'] = 'Non-Compliant Return';
        }

        return $payload;
    }

    /**
     * @return array{first_item: float|null, additional_item: float|null, non_compliant: float|null}
     */
    private function defaultsForReturn(ClientAccountReturn $return): array
    {
        $accountId = (int) ($return->client_account_id ?? 0);
        if ($accountId <= 0) {
            return [
                'first_item' => null,
                'additional_item' => null,
                'non_compliant' => null,
            ];
        }

        $account = ClientAccount::query()
            ->with(['feeItems.pricingTemplate'])
            ->find($accountId);

        if ($account === null) {
            return [
                'first_item' => null,
                'additional_item' => null,
                'non_compliant' => null,
            ];
        }

        return $this->accountDefaults($account);
    }

    private function persistResolvedFees(
        ClientAccountReturn $return,
        ?float $first,
        ?float $additional,
        ?float $nonCompliant
    ): void {
        $changed = false;
        if ($first !== null && (float) ($return->return_fee_first_item ?? 0) != $first) {
            $return->return_fee_first_item = round($first, 4);
            $changed = true;
        }
        if ($additional !== null && (float) ($return->return_fee_additional_item ?? 0) != $additional) {
            $return->return_fee_additional_item = round($additional, 4);
            $changed = true;
        }
        if ($nonCompliant !== null
            && $return->isNonCompliant()
            && (float) ($return->return_fee_non_compliant ?? 0) != $nonCompliant) {
            $return->return_fee_non_compliant = round($nonCompliant, 4);
            $changed = true;
        }
        if ($changed) {
            $return->saveQuietly();
        }
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
        $fees = $this->serializeReturnFees($return);

        return (float) ($fees['first_item'] ?? 0);
    }

    public function additionalItemFeeAmount(ClientAccountReturn $return): float
    {
        $fees = $this->serializeReturnFees($return);

        return (float) ($fees['additional_item'] ?? 0);
    }

    public function nonCompliantFeeAmount(ClientAccountReturn $return): float
    {
        $fees = $this->serializeReturnFees($return);

        return (float) ($fees['non_compliant'] ?? 0);
    }

    /**
     * @param  mixed  $stored
     */
    private function shouldApplyDefault($stored, ?float $default): bool
    {
        if ($default === null) {
            return false;
        }
        if ($stored === null) {
            return true;
        }

        // Replace empty placeholder zeros with the account's configured fee.
        return (float) $stored == 0.0 && $default > 0;
    }

    private function amountForLine(ClientAccount $account, string $lineCode): ?float
    {
        $candidates = [];
        foreach ($account->feeItems as $fee) {
            if (! $fee instanceof ClientAccountFee) {
                continue;
            }
            if (! $this->isReturnsFee($fee)) {
                continue;
            }
            if (! $this->feeMatchesLineCode($fee, $lineCode)) {
                continue;
            }
            $candidates[] = $fee->amount !== null ? (float) $fee->amount : null;
        }

        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $amount) {
            if ($amount !== null && $amount > 0) {
                return $amount;
            }
        }
        foreach ($candidates as $amount) {
            if ($amount !== null) {
                return $amount;
            }
        }

        return null;
    }

    private function isReturnsFee(ClientAccountFee $fee): bool
    {
        if ($fee->fee_group === ClientAccountFee::GROUP_RETURNS) {
            return true;
        }

        // Catalog/template rows occasionally land with a mismatched group; recover by name.
        $haystack = $this->feeHaystack($fee);

        return str_contains($haystack, 'return');
    }

    private function feeMatchesLineCode(ClientAccountFee $fee, string $lineCode): bool
    {
        if ($fee->line_code === $lineCode) {
            return true;
        }

        $haystack = $this->feeHaystack($fee);
        if ($haystack === '') {
            return false;
        }

        $aliases = self::LINE_LABEL_ALIASES[$lineCode] ?? [];
        foreach ($aliases as $alias) {
            if ($haystack === $this->normalizeFeeKey($alias)) {
                return true;
            }
        }

        if ($lineCode === ClientAccountFee::LINE_RETURNS_NON_COMPLIANT) {
            return str_contains($haystack, 'non') && str_contains($haystack, 'compliant');
        }
        if ($lineCode === ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS) {
            return str_contains($haystack, 'return') && str_contains($haystack, 'additional');
        }
        if ($lineCode === ClientAccountFee::LINE_RETURNS_PROCESSING) {
            if (str_contains($haystack, 'additional') || str_contains($haystack, 'assembly')
                || str_contains($haystack, 'repack') || str_contains($haystack, 'disposal')
                || (str_contains($haystack, 'non') && str_contains($haystack, 'compliant'))) {
                return false;
            }

            return str_contains($haystack, 'return')
                && (str_contains($haystack, 'process') || str_contains($haystack, 'first'));
        }

        return false;
    }

    private function feeHaystack(ClientAccountFee $fee): string
    {
        $parts = [
            (string) ($fee->label ?? ''),
            (string) ($fee->line_code ?? ''),
        ];
        if ($fee->relationLoaded('pricingTemplate') && $fee->pricingTemplate !== null) {
            $parts[] = (string) ($fee->pricingTemplate->name ?? '');
        }

        return $this->normalizeFeeKey(implode(' ', $parts));
    }

    private function normalizeFeeKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
