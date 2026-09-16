<?php

namespace App\Services;

use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyLocation;
use App\Models\ShopifyWarehouseLocation;
use App\Support\ShopifyError;
use App\Support\ShopifyGid;
use Illuminate\Support\Facades\Cache;
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
        $seenIds = [];
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
                $seenIds[] = $id;
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
                $this->mirrorWarehouseLocation($location, (bool) $location->active);
                $count++;
            }

            $pageInfo = is_array($conn['pageInfo'] ?? null) ? $conn['pageInfo'] : [];
            $cursor = ShopifyClient::nextPageCursor($cursor, $pageInfo, $page, 10);
        } while ($cursor !== null);

        // Deactivate CRM rows that Shopify no longer returns, and normalize location IDs.
        if ($seenIds !== []) {
            $existing = ShopifyLocation::query()
                ->where('connection_id', $connection->id)
                ->get();
            foreach ($existing as $location) {
                $normalized = ShopifyGid::toId((string) $location->shopify_location_id);
                if ($normalized === '' || ! in_array($normalized, $seenIds, true)) {
                    $this->deactivateWarehouseLocation($normalized);
                    $location->active = false;
                    $location->save();
                    continue;
                }
                if ((string) $location->shopify_location_id !== $normalized) {
                    $duplicate = ShopifyLocation::query()
                        ->where('connection_id', $connection->id)
                        ->where('shopify_location_id', $normalized)
                        ->where('id', '!=', $location->id)
                        ->first();
                    if ($duplicate !== null) {
                        $location->delete();
                    } else {
                        $location->shopify_location_id = $normalized;
                        $location->save();
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Pull Shopify locations for every connected store, but not more than once a minute.
     */
    public function syncConnectedLocationsIfStale(): void
    {
        if (Cache::has('shopify.locations.pull')) {
            return;
        }
        Cache::put('shopify.locations.pull', 1, 60);

        $connections = ClientAccountShopifyConnection::query()
            ->where('status', ClientAccountShopifyConnection::STATUS_CONNECTED)
            ->get();
        foreach ($connections as $connection) {
            if (! $connection->hasCredentials()) {
                continue;
            }
            try {
                $this->importLocations($connection, $this->client->forConnection($connection));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Create or rename a CRM location from a Shopify locations webhook.
     *
     * @param  array<string, mixed>  $payload
     */
    public function upsertLocationFromPayload(
        ClientAccountShopifyConnection $connection,
        array $payload,
        bool $deleted = false
    ): ?ShopifyLocation {
        $id = ShopifyGid::toId((string) ($payload['admin_graphql_api_id'] ?? ''));
        if ($id === '') {
            $id = ShopifyGid::toId((string) ($payload['id'] ?? ''));
        }
        if ($id === '') {
            $id = ShopifyGid::numericIdString($payload['id'] ?? null);
        }
        if ($id === '') {
            return null;
        }

        $location = ShopifyLocation::query()->firstOrNew([
            'connection_id' => $connection->id,
            'shopify_location_id' => $id,
        ]);
        $isNew = ! $location->exists;

        if ($deleted) {
            if ($isNew) {
                return null;
            }
            $location->active = false;
            $location->save();
            $this->deactivateWarehouseLocation($id);

            return $location;
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name !== '') {
            $location->name = $name;
        } elseif ($isNew) {
            $location->name = 'Location '.$id;
        }
        if (array_key_exists('active', $payload)) {
            $location->active = (bool) $payload['active'];
        } elseif ($isNew) {
            $location->active = true;
        }
        $location->legacy = (bool) ($payload['legacy'] ?? $location->legacy ?? false);
        $address = $this->addressFromLocationPayload($payload);
        if ($address !== []) {
            $location->address_json = $address;
        }
        if ($isNew) {
            $location->import_orders = true;
            $location->sync_inventory = true;
        }
        $location->save();
        $this->mirrorWarehouseLocation($location, (bool) $location->active);

        return $location;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function addressFromLocationPayload(array $payload): array
    {
        $source = is_array($payload['address'] ?? null) ? $payload['address'] : $payload;
        $address = [];
        foreach (['address1', 'address2', 'city', 'province', 'country', 'zip'] as $key) {
            $value = trim((string) ($source[$key] ?? ''));
            if ($value !== '') {
                $address[$key] = $value;
            }
        }

        return $address;
    }

    private function deactivateWarehouseLocation(string $shopifyLocationId): void
    {
        $shopifyLocationId = trim($shopifyLocationId);
        if ($shopifyLocationId === '') {
            return;
        }
        ShopifyWarehouseLocation::query()
            ->where('shopify_location_id', $shopifyLocationId)
            ->update(['active' => false]);
    }

    private function mirrorWarehouseLocation(ShopifyLocation $location, bool $active): void
    {
        $sid = trim((string) $location->shopify_location_id);
        if ($sid === '') {
            return;
        }
        $name = trim((string) $location->name);
        if ($name === '') {
            $name = 'Location '.$sid;
        }

        $row = ShopifyWarehouseLocation::query()->where('shopify_location_id', $sid)->first();
        if ($row === null) {
            $row = ShopifyWarehouseLocation::query()
                ->where('name', $name)
                ->whereNull('shopify_location_id')
                ->first();
        }
        if ($row === null) {
            $row = new ShopifyWarehouseLocation();
            $row->shopify_location_id = $sid;
            $row->type = 'Shopify';
            $row->pickable = false;
            $row->sellable = true;
        } else {
            $row->shopify_location_id = $sid;
        }
        $row->name = $this->uniqueWarehouseName($name, $row->exists ? (int) $row->id : null);
        $row->active = $active;
        $row->save();
    }

    private function uniqueWarehouseName(string $name, ?int $ignoreId): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'Location';
        }
        $candidate = $name;
        $suffix = 2;
        while ($this->warehouseNameTaken($candidate, $ignoreId)) {
            $candidate = $name.' '.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function warehouseNameTaken(string $name, ?int $ignoreId): bool
    {
        $query = ShopifyWarehouseLocation::query()->where('name', $name);
        if ($ignoreId !== null && $ignoreId > 0) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
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
