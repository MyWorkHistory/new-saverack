<?php

namespace App\Services;

use App\Models\ShopifyWarehouseInventoryLog;
use App\Models\ShopifyWarehouseLocation;
use App\Models\User;
use Illuminate\Support\Str;

class ShopifyWarehouseInventoryLogService
{
    /**
     * Record a bin-to-bin transfer as two log rows (out + in).
     */
    public function recordTransfer(
        int $variantId,
        ShopifyWarehouseLocation $from,
        ShopifyWarehouseLocation $to,
        int $qty,
        int $fromOld,
        int $fromNew,
        int $toOld,
        int $toNew,
        ?User $actor = null
    ): string {
        $qty = abs($qty);
        $fromName = trim((string) $from->name);
        $toName = trim((string) $to->name);
        $note = sprintf('Transfer From %s to %s - QTY: %d', $fromName, $toName, $qty);
        $group = $this->nextTransferGroup();
        $userId = $actor !== null ? (int) $actor->id : null;

        ShopifyWarehouseInventoryLog::query()->create([
            'shopify_variant_id' => $variantId,
            'location_id' => (int) $from->id,
            'location_name' => $fromName,
            'user_id' => $userId,
            'type' => ShopifyWarehouseInventoryLog::TYPE_TRANSFER_OUT,
            'type_label' => ShopifyWarehouseInventoryLog::TYPE_LABEL_TRANSFER,
            'quantity_delta' => -$qty,
            'old_on_hand' => $fromOld,
            'new_on_hand' => $fromNew,
            'note' => $note,
            'transfer_group' => $group,
        ]);

        ShopifyWarehouseInventoryLog::query()->create([
            'shopify_variant_id' => $variantId,
            'location_id' => (int) $to->id,
            'location_name' => $toName,
            'user_id' => $userId,
            'type' => ShopifyWarehouseInventoryLog::TYPE_TRANSFER_IN,
            'type_label' => ShopifyWarehouseInventoryLog::TYPE_LABEL_TRANSFER,
            'quantity_delta' => $qty,
            'old_on_hand' => $toOld,
            'new_on_hand' => $toNew,
            'note' => $note,
            'transfer_group' => $group,
        ]);

        return $group;
    }

    public function nextTransferGroup(): string
    {
        return 'TRF-'.Str::upper(Str::random(6));
    }

    /**
     * Filter type options: Transfer + CRM adjustment reasons.
     *
     * @return list<string>
     */
    public function filterTypes(): array
    {
        $types = [ShopifyWarehouseInventoryLog::TYPE_LABEL_TRANSFER];
        foreach (\App\Models\ShopifyWarehouseLocation::addItemReasons() as $reason) {
            if (! in_array($reason, $types, true)) {
                $types[] = $reason;
            }
        }

        return $types;
    }
}
