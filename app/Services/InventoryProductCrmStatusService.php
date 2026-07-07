<?php

namespace App\Services;

use App\Models\InventoryProductCrmStatus;

class InventoryProductCrmStatusService
{
    /**
     * @param  list<string>  $skus
     */
    public function bulkSetActive(int $clientAccountId, array $skus, bool $active, ?int $updatedByUserId = null): int
    {
        if ($clientAccountId <= 0) {
            return 0;
        }

        $normalized = [];
        foreach ($skus as $sku) {
            $value = trim((string) $sku);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        if ($normalized === []) {
            return 0;
        }

        $updated = 0;
        foreach (array_keys($normalized) as $sku) {
            InventoryProductCrmStatus::query()->updateOrCreate(
                [
                    'client_account_id' => $clientAccountId,
                    'sku' => $sku,
                ],
                [
                    'crm_active' => $active,
                    'updated_by_user_id' => $updatedByUserId,
                ]
            );
            $updated++;
        }

        return $updated;
    }
}
