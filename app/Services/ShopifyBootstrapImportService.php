<?php

namespace App\Services;

use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyLocation;
use App\Support\ShopifyError;
use App\Support\ShopifyGid;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifyBootstrapImportService
{
    /** @var ShopifyClient */
    private $client;

    /** @var ShopifyProductSyncService */
    private $products;

    /** @var ShopifyOrderSyncService */
    private $orders;

    public function __construct(
        ShopifyClient $client,
        ShopifyProductSyncService $products,
        ShopifyOrderSyncService $orders
    ) {
        $this->client = $client;
        $this->products = $products;
        $this->orders = $orders;
    }

    /**
     * @return array{locations:int, products:int, variants:int, inventory_levels:int, orders:int}
     */
    public function importAll(ClientAccountShopifyConnection $connection): array
    {
        $api = $this->client->forConnection($connection);
        $locations = $this->importLocations($connection, $api);
        $orderCount = 0;
        $orderError = null;
        try {
            $orderCount = $this->orders->importOpenOrders($connection, $api);
        } catch (Throwable $e) {
            $orderError = $e->getMessage();
            Log::warning('shopify.import.orders_failed', [
                'connection_id' => $connection->id,
                'message' => $orderError,
            ]);
        }
        $catalog = $this->products->importActiveProducts($connection, $api);
        $levels = $this->importInventoryLevels($connection, $api);

        $connection->last_sync_at = now();
        $connection->last_product_sync_at = now();
        if ($orderError === null) {
            $connection->last_order_sync_at = now();
            $connection->last_error = null;
        } else {
            $connection->last_error = mb_substr(ShopifyError::staffMessage($orderError), 0, 1000);
        }
        $connection->status = ClientAccountShopifyConnection::STATUS_CONNECTED;
        $connection->save();

        return [
            'locations' => $locations,
            'products' => (int) ($catalog['products'] ?? 0),
            'variants' => (int) ($catalog['variants'] ?? 0),
            'inventory_levels' => $levels,
            'orders' => $orderCount,
        ];
    }

    private function importLocations(ClientAccountShopifyConnection $connection, ShopifyClient $api): int
    {
        $count = 0;
        $cursor = null;
        $page = 0;
        do {
            $page++;
            $data = $api->graphql(
                <<<'GQL'
query Locations($cursor: String) {
  locations(first: 50, after: $cursor) {
    pageInfo { hasNextPage endCursor }
    edges {
      node {
        id
        name
        isActive
        address {
          address1
          address2
          city
          province
          country
          zip
        }
      }
    }
  }
}
GQL
                ,
                ['cursor' => $cursor]
            );

            $conn = is_array($data['locations'] ?? null) ? $data['locations'] : [];
            foreach (($conn['edges'] ?? []) as $edge) {
                $node = is_array($edge['node'] ?? null) ? $edge['node'] : null;
                if ($node === null) {
                    continue;
                }
                $id = ShopifyGid::toId((string) ($node['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                ShopifyLocation::query()->updateOrCreate(
                    [
                        'connection_id' => $connection->id,
                        'shopify_location_id' => $id,
                    ],
                    [
                        'name' => (string) ($node['name'] ?? ''),
                        'active' => (bool) ($node['isActive'] ?? true),
                        'legacy' => false,
                        'address_json' => is_array($node['address'] ?? null) ? $node['address'] : null,
                    ]
                );
                $count++;
            }

            $pageInfo = is_array($conn['pageInfo'] ?? null) ? $conn['pageInfo'] : [];
            $cursor = ShopifyClient::nextPageCursor($cursor, $pageInfo, $page, 10);
        } while ($cursor !== null);

        return $count;
    }

    /**
     * Incremental inventory reconcile for cron (capped).
     */
    public function syncInventoryForConnection(ClientAccountShopifyConnection $connection, int $limit = 40): int
    {
        $limit = max(1, min(200, $limit));
        $api = $this->client->forConnection($connection);

        $itemIds = $connection->variants()
            ->whereNotNull('shopify_inventory_item_id')
            ->where('shopify_inventory_item_id', '!=', '')
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('shopify_inventory_item_id')
            ->unique()
            ->values()
            ->all();

        $count = 0;
        foreach ($itemIds as $inventoryItemId) {
            try {
                $count += $this->syncInventoryItemLevels($connection, $api, (string) $inventoryItemId);
            } catch (Throwable $e) {
                Log::warning('shopify.import.inventory_item_failed', [
                    'connection_id' => $connection->id,
                    'inventory_item_id' => $inventoryItemId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    private function importInventoryLevels(ClientAccountShopifyConnection $connection, ShopifyClient $api): int
    {
        $count = 0;
        $itemIds = $connection->variants()
            ->whereNotNull('shopify_inventory_item_id')
            ->where('shopify_inventory_item_id', '!=', '')
            ->pluck('shopify_inventory_item_id')
            ->unique()
            ->values()
            ->take(200)
            ->all();

        foreach (array_chunk($itemIds, 25) as $chunk) {
            foreach ($chunk as $inventoryItemId) {
                try {
                    $count += $this->syncInventoryItemLevels($connection, $api, (string) $inventoryItemId);
                } catch (Throwable $e) {
                    Log::warning('shopify.import.inventory_item_failed', [
                        'connection_id' => $connection->id,
                        'inventory_item_id' => $inventoryItemId,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $count;
    }

    public function syncInventoryItemLevels(
        ClientAccountShopifyConnection $connection,
        ShopifyClient $api,
        string $inventoryItemId
    ): int {
        $gid = str_starts_with($inventoryItemId, 'gid://')
            ? $inventoryItemId
            : ShopifyGid::of('InventoryItem', $inventoryItemId);
        $numericItemId = ShopifyGid::toId($inventoryItemId);
        $count = 0;
        $cursor = null;
        $page = 0;

        do {
            $page++;
            $data = $api->graphql(
                <<<'GQL'
query InventoryItemLevels($id: ID!, $cursor: String) {
  inventoryItem(id: $id) {
    id
    inventoryLevels(first: 50, after: $cursor) {
      pageInfo { hasNextPage endCursor }
      edges {
        node {
          id
          location { id }
          quantities(names: ["available"]) {
            name
            quantity
          }
        }
      }
    }
  }
}
GQL
                ,
                ['id' => $gid, 'cursor' => $cursor]
            );

            $item = is_array($data['inventoryItem'] ?? null) ? $data['inventoryItem'] : null;
            if ($item === null) {
                break;
            }
            $levels = is_array($item['inventoryLevels'] ?? null) ? $item['inventoryLevels'] : [];
            foreach (($levels['edges'] ?? []) as $edge) {
                $node = is_array($edge['node'] ?? null) ? $edge['node'] : null;
                if ($node === null) {
                    continue;
                }
                $locationId = ShopifyGid::toId((string) (($node['location']['id'] ?? '') ?: ''));
                $available = 0;
                foreach (($node['quantities'] ?? []) as $qtyRow) {
                    if (! is_array($qtyRow)) {
                        continue;
                    }
                    if (($qtyRow['name'] ?? '') === 'available') {
                        $available = (int) ($qtyRow['quantity'] ?? 0);
                    }
                }
                if ($locationId === '') {
                    continue;
                }
                ShopifyInventoryLevel::query()->updateOrCreate(
                    [
                        'connection_id' => $connection->id,
                        'shopify_inventory_item_id' => $numericItemId,
                        'shopify_location_id' => $locationId,
                    ],
                    [
                        'available' => $available,
                        'shopify_updated_at' => now(),
                    ]
                );
                $count++;
            }

            $pageInfo = is_array($levels['pageInfo'] ?? null) ? $levels['pageInfo'] : [];
            $cursor = ShopifyClient::nextPageCursor($cursor, $pageInfo, $page, 5);
        } while ($cursor !== null);

        return $count;
    }
}
