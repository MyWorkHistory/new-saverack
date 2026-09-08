<?php

namespace App\Services;

use App\Jobs\PushShopifyVariantInventoryJob;
use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProductVariant;
use App\Support\ShopifyGid;
use Illuminate\Support\Carbon;

/**
 * Roll CRM warehouse bin qty deltas into Shopify store inventory levels
 * (sync_inventory=true), then push to Shopify Admin after the HTTP response.
 */
class ShopifyWarehouseInventorySyncService
{
    /**
     * Apply a sellable available delta for a variant across synced Shopify locations.
     * Does not call Shopify Admin inline — schedules a background push.
     */
    public function applyAvailableDelta(ShopifyProductVariant $variant, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $variant->loadMissing('connection');
        $connection = $variant->connection;
        if ($connection === null) {
            return;
        }

        $itemId = ShopifyGid::toId(trim((string) ($variant->shopify_inventory_item_id ?? '')));
        if ($itemId === '') {
            return;
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
            return;
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

        PushShopifyVariantInventoryJob::dispatchAfterResponse((int) $variant->id);
    }
}