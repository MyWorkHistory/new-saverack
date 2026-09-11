<?php

namespace App\Services;

use App\Jobs\PushShopifyVariantInventoryJob;
use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProductVariant;
use App\Support\ShopifyGid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Roll CRM warehouse bin qty deltas into Shopify store inventory levels
 * (sync_inventory=true), then push to Shopify Admin (inline with queued retry).
 */
class ShopifyWarehouseInventorySyncService
{
    /** @var ShopifyProductSyncService */
    private $productSync;

    public function __construct(ShopifyProductSyncService $productSync)
    {
        $this->productSync = $productSync;
    }

    /**
     * Apply a sellable available delta for a variant across synced Shopify locations.
     * Attempts an inline Shopify Admin push; queues a retry job on failure.
     *
     * @return array{status: string, reason: ?string, pushed?: int}
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

        $locationIds = $this->syncLocationIds((int) $connection->id);
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

        try {
            $pushed = $this->productSync->pushInventoryToShopify($variant->fresh('connection'));
            if ((int) $pushed <= 0) {
                Log::warning('shopify.inventory.warehouse_delta_push_zero', [
                    'variant_id' => (int) $variant->id,
                    'delta' => $delta,
                ]);
                PushShopifyVariantInventoryJob::dispatch((int) $variant->id);

                return ['status' => 'queued', 'reason' => 'push_returned_zero'];
            }

            return [
                'status' => 'pushed',
                'reason' => null,
                'pushed' => (int) $pushed,
            ];
        } catch (Throwable $e) {
            report($e);
            Log::warning('shopify.inventory.warehouse_delta_inline_push_failed', [
                'variant_id' => (int) $variant->id,
                'message' => $e->getMessage(),
            ]);
            PushShopifyVariantInventoryJob::dispatch((int) $variant->id);

            return ['status' => 'queued', 'reason' => null];
        }
    }

    /**
     * @return list<string>
     */
    private function syncLocationIds(int $connectionId): array
    {
        $ids = ShopifyLocation::query()
            ->where('connection_id', $connectionId)
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

        if ($ids !== []) {
            return $ids;
        }

        // No Sync Inventory toggle — still push to every known Shopify location so
        // warehouse Add Inventory updates Shopify Admin (e.g. "Shop location").
        return ShopifyLocation::query()
            ->where('connection_id', $connectionId)
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
    }
}
