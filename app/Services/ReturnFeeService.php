<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\ClientAccountReturn;
use Illuminate\Validation\ValidationException;

class ReturnFeeService
{
    /**
     * @return array{first_item: float|null, additional_item: float|null, non_compliant: float|null}
     */
    public function accountDefaults(ClientAccount $account): array
    {
        $account->loadMissing('feeItems');
        $first = null;
        $additional = null;
        $nonCompliant = null;
        foreach ($account->feeItems as $fee) {
            if (! $fee instanceof ClientAccountFee) {
                continue;
            }
            if ($fee->fee_group !== ClientAccountFee::GROUP_RETURNS) {
                continue;
            }
            if ($fee->line_code === ClientAccountFee::LINE_RETURNS_PROCESSING) {
                $first = $fee->amount !== null ? (float) $fee->amount : null;
            } elseif ($fee->line_code === ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS) {
                $additional = $fee->amount !== null ? (float) $fee->amount : null;
            } elseif ($fee->line_code === ClientAccountFee::LINE_RETURNS_NON_COMPLIANT) {
                $nonCompliant = $fee->amount !== null ? (float) $fee->amount : null;
            }
        }

        return [
            'first_item' => $first,
            'additional_item' => $additional,
            'non_compliant' => $nonCompliant,
        ];
    }

    public function seedReturnFees(ClientAccountReturn $return): void
    {
        $return->loadMissing('clientAccount');
        if ($return->clientAccount === null) {
            return;
        }

        $defaults = $this->accountDefaults($return->clientAccount);
        $changed = false;
        if ($return->return_fee_first_item === null) {
            $return->return_fee_first_item = $defaults['first_item'];
            $changed = true;
        }
        if ($return->return_fee_additional_item === null) {
            $return->return_fee_additional_item = $defaults['additional_item'];
            $changed = true;
        }
        if ($return->isNonCompliant() && $return->return_fee_non_compliant === null) {
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
}
