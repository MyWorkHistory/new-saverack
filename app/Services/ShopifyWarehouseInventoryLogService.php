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
        ?User $actor = null,
        ?string $reason = null
    ): string {
        $qty = abs($qty);
        $fromName = trim((string) $from->name);
        $toName = trim((string) $to->name);
        $reason = trim((string) ($reason ?? ''));
        $note = sprintf('Transfer From %s to %s - QTY: %d', $fromName, $toName, $qty);
        if ($reason !== '') {
            $note .= ' - '.$reason;
        }
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

    /**
     * Record an add / edit / remove quantity adjustment at one location.
     */
    public function recordAdjustment(
        int $variantId,
        ShopifyWarehouseLocation $location,
        int $oldQty,
        int $newQty,
        string $reason,
        ?User $actor = null,
        ?string $note = null
    ): void {
        $oldQty = max(0, $oldQty);
        $newQty = max(0, $newQty);
        $delta = $newQty - $oldQty;
        if ($delta === 0) {
            return;
        }

        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Client-Requested Adjustments';
        }

        if ($note === null || trim($note) === '') {
            if ($oldQty === 0 && $newQty > 0) {
                $note = sprintf('Added %d', $newQty);
            } elseif ($newQty === 0) {
                $note = sprintf('Removed %d', $oldQty);
            } else {
                $note = sprintf('Adjusted from %d to %d', $oldQty, $newQty);
            }
        }

        ShopifyWarehouseInventoryLog::query()->create([
            'shopify_variant_id' => $variantId,
            'location_id' => (int) $location->id,
            'location_name' => trim((string) $location->name),
            'user_id' => $actor !== null ? (int) $actor->id : null,
            'type' => ShopifyWarehouseInventoryLog::TYPE_ADJUSTMENT,
            'type_label' => $reason,
            'quantity_delta' => $delta,
            'old_on_hand' => $oldQty,
            'new_on_hand' => $newQty,
            'note' => $note,
            'transfer_group' => null,
        ]);
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
