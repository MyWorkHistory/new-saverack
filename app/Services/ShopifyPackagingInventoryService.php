<?php

namespace App\Services;

use App\Models\ShopifyPackagingInventoryLog;
use App\Models\ShopifyPackagingItem;
use App\Models\ShopifyPackagingLocationItem;
use App\Models\ShopifyWarehouseLocation;
use App\Models\User;
use App\Services\AsnReceivingService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShopifyPackagingInventoryService
{
    /**
     * @return array{location_groups: list<array<string, mixed>>, total_on_hand: int}
     */
    public function locationSummary(ShopifyPackagingItem $item): array
    {
        $rows = ShopifyPackagingLocationItem::query()
            ->where('shopify_packaging_item_id', $item->id)
            ->with('location')
            ->get();

        $grouped = [
            'pick' => [],
            'backstock' => [],
            'other' => [],
        ];
        $receivingName = strtolower(AsnReceivingService::RECEIVING_LOCATION_NAME);

        foreach ($rows as $row) {
            $location = $row->location;
            if ($location === null || (int) $row->available <= 0) {
                continue;
            }
            $entry = [
                'item_id' => (int) $row->id,
                'location_id' => (int) $location->id,
                'name' => (string) $location->name,
                'available' => (int) $row->available,
                'type' => $location->type,
                'pickable' => (bool) $location->pickable,
                'sellable' => (bool) $location->sellable,
            ];
            $nameLower = strtolower(trim((string) $location->name));
            if ($nameLower === $receivingName || ShopifyWarehouseLocation::isPickingCartType($location->type)) {
                $grouped['other'][] = $entry;
            } elseif ($location->pickable) {
                $grouped['pick'][] = $entry;
            } else {
                $grouped['backstock'][] = $entry;
            }
        }

        $labels = [
            'pick' => 'Pick Locations',
            'backstock' => 'Backstock Locations',
            'other' => 'Picking Cart',
        ];
        $groups = [];
        $total = 0;
        foreach ($labels as $key => $label) {
            $locations = $grouped[$key];
            $qty = 0;
            foreach ($locations as $loc) {
                $qty += (int) $loc['available'];
            }
            $total += $qty;
            $groups[] = [
                'key' => $key,
                'label' => $label,
                'count' => $qty,
                'locations' => $locations,
            ];
        }

        return [
            'location_groups' => $groups,
            'total_on_hand' => $total > 0 ? $total : (int) $item->on_hand,
        ];
    }

    public function assign(ShopifyPackagingItem $item, int $locationId, int $qty, string $reason, ?User $actor): void
    {
        $location = ShopifyWarehouseLocation::query()->find($locationId);
        if ($location === null) {
            throw ValidationException::withMessages([
                'location_id' => ['Select a location.'],
            ]);
        }
        $qty = max(1, $qty);
        $row = ShopifyPackagingLocationItem::query()->firstOrNew([
            'location_id' => $location->id,
            'shopify_packaging_item_id' => $item->id,
        ]);
        $old = (int) $row->available;
        $row->available = $old + $qty;
        $row->save();
        $this->recordAdjustment($item, $location, $old, (int) $row->available, $reason, $actor, sprintf('Added %d', $qty));
        $this->syncOnHand($item);
    }

    public function updateQty(ShopifyPackagingItem $item, ShopifyPackagingLocationItem $row, int $qty, string $reason, ?User $actor): void
    {
        $this->assertRowBelongs($item, $row);
        $location = $row->location ?: ShopifyWarehouseLocation::query()->find($row->location_id);
        if ($location === null) {
            throw ValidationException::withMessages([
                'available' => ['Location was not found.'],
            ]);
        }
        $old = (int) $row->available;
        $row->available = max(0, $qty);
        $row->save();
        $this->recordAdjustment($item, $location, $old, (int) $row->available, $reason, $actor);
        $this->syncOnHand($item);
    }

    public function transfer(
        ShopifyPackagingItem $item,
        ShopifyPackagingLocationItem $row,
        int $toLocationId,
        int $qty,
        string $reason,
        ?User $actor
    ): void {
        $this->assertRowBelongs($item, $row);
        $from = $row->location ?: ShopifyWarehouseLocation::query()->find($row->location_id);
        if ($from === null) {
            throw ValidationException::withMessages([
                'quantity' => ['Source location was not found.'],
            ]);
        }
        if ($toLocationId === (int) $from->id) {
            throw ValidationException::withMessages([
                'to_location_id' => ['Choose a different destination location.'],
            ]);
        }
        $to = ShopifyWarehouseLocation::query()->find($toLocationId);
        if ($to === null) {
            throw ValidationException::withMessages([
                'to_location_id' => ['Destination location was not found.'],
            ]);
        }
        $qty = max(1, $qty);
        if ($qty > (int) $row->available) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity is higher than the available amount.'],
            ]);
        }

        $fromOld = (int) $row->available;
        $row->available = $fromOld - $qty;
        $row->save();

        $dest = ShopifyPackagingLocationItem::query()->firstOrNew([
            'location_id' => $to->id,
            'shopify_packaging_item_id' => $item->id,
        ]);
        $toOld = (int) $dest->available;
        $dest->available = $toOld + $qty;
        $dest->save();

        $this->recordTransfer($item, $from, $to, $qty, $fromOld, (int) $row->available, $toOld, (int) $dest->available, $actor, $reason);
        $this->syncOnHand($item);
    }

    public function syncOnHand(ShopifyPackagingItem $item): void
    {
        $sum = (int) ShopifyPackagingLocationItem::query()
            ->where('shopify_packaging_item_id', $item->id)
            ->sum('available');
        if ($sum > 0 || ShopifyPackagingLocationItem::query()->where('shopify_packaging_item_id', $item->id)->exists()) {
            $item->on_hand = max(0, $sum);
            $item->save();
        }
    }

    private function assertRowBelongs(ShopifyPackagingItem $item, ShopifyPackagingLocationItem $row): void
    {
        if ((int) $row->shopify_packaging_item_id !== (int) $item->id) {
            abort(404);
        }
    }

    private function recordAdjustment(
        ShopifyPackagingItem $item,
        ShopifyWarehouseLocation $location,
        int $oldQty,
        int $newQty,
        string $reason,
        ?User $actor,
        ?string $note = null
    ): void {
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

        ShopifyPackagingInventoryLog::query()->create([
            'shopify_packaging_item_id' => $item->id,
            'location_id' => $location->id,
            'location_name' => trim((string) $location->name),
            'user_id' => $actor !== null ? (int) $actor->id : null,
            'type' => ShopifyPackagingInventoryLog::TYPE_ADJUSTMENT,
            'type_label' => $reason,
            'quantity_delta' => $delta,
            'old_on_hand' => $oldQty,
            'new_on_hand' => $newQty,
            'note' => $note,
        ]);
    }

    private function recordTransfer(
        ShopifyPackagingItem $item,
        ShopifyWarehouseLocation $from,
        ShopifyWarehouseLocation $to,
        int $qty,
        int $fromOld,
        int $fromNew,
        int $toOld,
        int $toNew,
        ?User $actor,
        string $reason
    ): void {
        $fromName = trim((string) $from->name);
        $toName = trim((string) $to->name);
        $reason = trim($reason);
        $note = sprintf('Transfer From %s to %s - QTY: %d', $fromName, $toName, $qty);
        if ($reason !== '') {
            $note .= ' - '.$reason;
        }
        $group = 'TRF-'.Str::upper(Str::random(6));
        $userId = $actor !== null ? (int) $actor->id : null;

        ShopifyPackagingInventoryLog::query()->create([
            'shopify_packaging_item_id' => $item->id,
            'location_id' => $from->id,
            'location_name' => $fromName,
            'user_id' => $userId,
            'type' => ShopifyPackagingInventoryLog::TYPE_TRANSFER_OUT,
            'type_label' => ShopifyPackagingInventoryLog::TYPE_LABEL_TRANSFER,
            'quantity_delta' => -$qty,
            'old_on_hand' => $fromOld,
            'new_on_hand' => $fromNew,
            'note' => $note,
            'transfer_group' => $group,
        ]);
        ShopifyPackagingInventoryLog::query()->create([
            'shopify_packaging_item_id' => $item->id,
            'location_id' => $to->id,
            'location_name' => $toName,
            'user_id' => $userId,
            'type' => ShopifyPackagingInventoryLog::TYPE_TRANSFER_IN,
            'type_label' => ShopifyPackagingInventoryLog::TYPE_LABEL_TRANSFER,
            'quantity_delta' => $qty,
            'old_on_hand' => $toOld,
            'new_on_hand' => $toNew,
            'note' => $note,
            'transfer_group' => $group,
        ]);
    }
}
