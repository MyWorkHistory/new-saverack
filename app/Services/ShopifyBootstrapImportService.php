<?php

namespace App\Services;

use App\Models\ClientAccountShopifyConnection;
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
            'inventory_levels' => 0,
            'orders' => $orderCount,
        ];
    }

    public function importLocationsOnly(ClientAccountShopifyConnection $connection): int
    {
        $api = $this->client->forConnection($connection);
        $count = $this->importLocations($connection, $api);
        $connection->status = ClientAccountShopifyConnection::STATUS_CONNECTED;
        $connection->last_sync_at = now();
        $connection->last_error = null;
        $connection->save();

        return $count;
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
                $location = ShopifyLocation::query()->firstOrNew([
                    'connection_id' => $connection->id,
                    'shopify_location_id' => $id,
                ]);
                $isNew = ! $location->exists;
                $location->name = (string) ($node['name'] ?? '');
                $location->active = (bool) ($node['isActive'] ?? true);
                $location->legacy = false;
                $location->address_json = is_array($node['address'] ?? null) ? $node['address'] : null;
                if ($isNew) {
                    $location->import_orders = true;
                    $location->sync_inventory = true;
                }
                $location->save();
                $count++;
            }

            $pageInfo = is_array($conn['pageInfo'] ?? null) ? $conn['pageInfo'] : [];
            $cursor = ShopifyClient::nextPageCursor($cursor, $pageInfo, $page, 10);
        } while ($cursor !== null);

        return $count;
    }

    /**
     * Shopify → CRM inventory pull is disabled. Qty is CRM-owned.
     */
    public function syncInventoryForConnection(ClientAccountShopifyConnection $connection, int $limit = 40): int
    {
        return 0;
    }

    /**
     * Shopify → CRM inventory pull is disabled. Qty is CRM-owned.
     */
    public function syncInventoryItemLevels(
        ClientAccountShopifyConnection $connection,
        ShopifyClient $api,
        string $inventoryItemId
    ): int {
        return 0;
    }
}
