<?php

namespace App\Services;

use App\Jobs\PushShopifyVariantInventoryJob;
use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProductVariant;
use App\Support\ShopifyGid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Roll CRM warehouse bin qty deltas into Shopify store inventory levels
 * (sync_inventory=true), then queue a background Shopify Admin push.
 */
class ShopifyWarehouseInventorySyncService
{
    /**
     * Apply a sellable available delta for a variant across synced Shopify locations.
     * Does not call Shopify Admin inline — queues a background push.
     *
     * @return array{status: string, reason: ?string}
     */
    public function applyAvailableDelta(ShopifyProductVariant $variant, int $delta): array
    {
        if ($delta === 0) {
            return ['status' => 'noop', 'reason' => null];
        }

        $variant->loadMissing('connection');
        $connection = $variant->connection;
        if ($connection === null) {
            Log::info('shopify.inventory.warehouse_delta_skipped', [
                'variant_id' => (int) $variant->id,
                'reason' => 'no_connection',
                'delta' => $delta,
            ]);

            return ['status' => 'skipped', 'reason' => 'no_connection'];
        }

        $itemId = ShopifyGid::toId(trim((string) ($variant->shopify_inventory_item_id ?? '')));
        if ($itemId === '') {
            Log::info('shopify.inventory.warehouse_delta_skipped', [
                'variant_id' => (int) $variant->id,
                'reason' => 'missing_inventory_item_id',
                'delta' => $delta,
            ]);

            return ['status' => 'skipped', 'reason' => 'missing_inventory_item_id'];
        }

        $locationIds = ShopifyLocation::query()
            ->where('connection_id', (int) $connection->id)
            ->where('sync_inventory', true)
            ->pluck('shopify_location_id')
            ->map(static function ($id) {
                return ShopifyGid::toId((string) $id);
            })
            ->filter(static function ($id) {
                return $id !== '';
            })
            ->unique()
            ->values()
            ->all();

        if ($locationIds === []) {
            Log::info('shopify.inventory.warehouse_delta_skipped', [
                'variant_id' => (int) $variant->id,
                'reason' => 'no_sync_inventory_locations',
                'delta' => $delta,
            ]);

            return ['status' => 'skipped', 'reason' => 'no_sync_inventory_locations'];
        }

        $now = Carbon::now();
        foreach ($locationIds as $locationId) {
            /** @var ShopifyInventoryLevel $level */
            $level = ShopifyInventoryLevel::query()->firstOrNew([
                'connection_id' => (int) $connection->id,
                'shopify_inventory_item_id' => $itemId,
                'shopify_location_id' => $locationId,
            ]);
            $current = (int) ($level->available ?? 0);
            $level->available = max(0, $current + $delta);
            $level->crm_set_at = $now;
            $level->save();
        }

        PushShopifyVariantInventoryJob::dispatch((int) $variant->id);

        return ['status' => 'queued', 'reason' => null];
    }
}
