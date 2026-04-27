<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ShipHeroInventoryService
{
    /** @var ShipHeroClient */
    protected $client;

    public function __construct(ShipHeroClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return list<array{id: string, legacy_id: int|null, identifier: string|null, label: string}>
     */
    public function listWarehouses(): array
    {
        return Cache::remember('shiphero.warehouses', now()->addHour(), function () {
            $graphql = <<<'GQL'
query ShipHeroWarehouses {
  account {
    data {
      warehouses {
        id
        legacy_id
        identifier
        address {
          name
        }
      }
    }
  }
}
GQL;

            $json = $this->client->query($graphql);
            $rows = data_get($json, 'data.account.data.warehouses');
            if (! is_array($rows)) {
                return [];
            }

            $out = [];
            foreach ($rows as $w) {
                if (! is_array($w)) {
                    continue;
                }
                $id = isset($w['id']) && is_string($w['id']) ? $w['id'] : '';
                if ($id === '') {
                    continue;
                }
                $identifier = isset($w['identifier']) && is_string($w['identifier']) ? $w['identifier'] : null;
                $addrName = data_get($w, 'address.name');
                $addrName = is_string($addrName) ? $addrName : null;
                $label = $identifier ?? $addrName ?? $id;
                $legacy = $w['legacy_id'] ?? null;
                $out[] = [
                    'id' => $id,
                    'legacy_id' => is_int($legacy) ? $legacy : (is_numeric($legacy) ? (int) $legacy : null),
                    'identifier' => $identifier,
                    'label' => $label,
                ];
            }

            return $out;
        });
    }

    /**
     * @return array<string, mixed>|null Normalized product payload or null if not found
     */
    /**
     * @param  string|null  $customerAccountId  ShipHero GraphQL `customer_account_id` (3PL), or null for brand-level
     */
    public function searchProduct(string $term, ?string $warehouseId = null, ?string $customerAccountId = null): ?array
    {
        $term = trim($term);
        if ($term === '') {
            return null;
        }

        $barcodeTerm = $this->normalizeBarcodeTerm($term);

        if ($this->looksLikeBarcode($term)) {
            $data = $this->fetchProductByBarcode($barcodeTerm, $customerAccountId);
            if ($data === null) {
                $data = $this->fetchProductBySku($term, $customerAccountId);
            }
        } else {
            $data = $this->fetchProductBySku($term, $customerAccountId);
            if ($data === null) {
                $data = $this->fetchProductByBarcode($term, $customerAccountId);
            }
        }

        if ($data === null) {
            return null;
        }

        return $this->normalizeProduct($data, $warehouseId);
    }

    /**
     * Replace on-hand quantity for one SKU at one location (absolute quantity).
     *
     * @return array<string, mixed> Normalized single-warehouse slice (same shape as search warehouses entries)
     */
    /**
     * @param  string|null  $customerAccountId  ShipHero GraphQL `customer_account_id` (3PL), or null
     */
    public function replaceLocationQuantity(
        string $sku,
        string $warehouseId,
        string $locationId,
        int $quantity,
        string $reason,
        ?string $customerAccountId = null
    ): array {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('SKU is required.');
        }
        $warehouseId = trim($warehouseId);
        $locationId = trim($locationId);
        if ($warehouseId === '' || $locationId === '') {
            throw new RuntimeException('warehouse_id and location_id are required.');
        }
        if ($quantity < 0) {
            throw new RuntimeException('quantity must be zero or greater.');
        }

        $input = [
            'sku' => $sku,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'quantity' => $quantity,
            'reason' => $reason !== '' ? $reason : 'CRM inventory replace',
            'includes_non_sellable' => false,
        ];
        if (is_string($customerAccountId) && trim($customerAccountId) !== '') {
            $input['customer_account_id'] = trim($customerAccountId);
        }

        $graphql = <<<'GQL'
mutation ShipHeroInventoryReplace($data: ReplaceInventoryInput!) {
  inventory_replace(data: $data) {
    request_id
    complexity
    warehouse_product {
      warehouse_id
      warehouse {
        identifier
        company_name
      }
      locations(first: 100) {
        edges {
          node {
            id
            location_id
            quantity
            location {
              name
            }
          }
        }
      }
    }
  }
}
GQL;

        $json = $this->client->query($graphql, ['data' => $input]);
        $wp = data_get($json, 'data.inventory_replace.warehouse_product');
        if (! is_array($wp)) {
            throw new RuntimeException('ShipHero did not return warehouse_product after replace.');
        }

        $wid = isset($wp['warehouse_id']) && is_string($wp['warehouse_id']) ? $wp['warehouse_id'] : $warehouseId;
        $whName = $this->warehouseDisplayName(is_array($wp['warehouse'] ?? null) ? $wp['warehouse'] : []);

        return [
            'warehouse_id' => $wid,
            'warehouse_name' => $whName,
            'locations' => $this->normalizeLocations($wp['locations'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null  product.data
     */
    private function fetchProductBySku(string $sku, ?string $customerAccountId): ?array
    {
        $graphql = <<<'GQL'
query ShipHeroProductBySku($sku: String!, $customer_account_id: String) {
  product(sku: $sku, customer_account_id: $customer_account_id) {
    data {
      id
      sku
      name
      barcode
      warehouse_products {
        warehouse_id
        warehouse {
          identifier
          company_name
        }
        locations(first: 100) {
          edges {
            node {
              id
              location_id
              quantity
              location {
                name
              }
            }
          }
        }
      }
    }
  }
}
GQL;
        $vars = array_merge(['sku' => $sku], $this->customerAccountVariables($customerAccountId));
        $json = $this->client->query($graphql, $vars);
        $data = data_get($json, 'data.product.data');

        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>|null  product.data
     */
    private function fetchProductByBarcode(string $barcode, ?string $customerAccountId): ?array
    {
        $graphql = <<<'GQL'
query ShipHeroProductByBarcode($barcode: String!, $customer_account_id: String) {
  product(barcode: $barcode, customer_account_id: $customer_account_id) {
    data {
      id
      sku
      name
      barcode
      warehouse_products {
        warehouse_id
        warehouse {
          identifier
          company_name
        }
        locations(first: 100) {
          edges {
            node {
              id
              location_id
              quantity
              location {
                name
              }
            }
          }
        }
      }
    }
  }
}
GQL;
        $vars = array_merge(['barcode' => $barcode], $this->customerAccountVariables($customerAccountId));
        $json = $this->client->query($graphql, $vars);
        $data = data_get($json, 'data.product.data');

        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeProduct(array $data, ?string $warehouseFilter): array
    {
        $warehousesOut = [];
        $wps = $data['warehouse_products'] ?? null;
        if (! is_array($wps)) {
            $wps = [];
        }

        foreach ($wps as $wp) {
            if (! is_array($wp)) {
                continue;
            }
            $wid = isset($wp['warehouse_id']) && is_string($wp['warehouse_id']) ? $wp['warehouse_id'] : '';
            if ($wid === '') {
                continue;
            }
            if (is_string($warehouseFilter) && $warehouseFilter !== '' && $wid !== $warehouseFilter) {
                continue;
            }
            $wh = is_array($wp['warehouse'] ?? null) ? $wp['warehouse'] : [];
            $warehousesOut[] = [
                'warehouse_id' => $wid,
                'warehouse_name' => $this->warehouseDisplayName($wh),
                'locations' => $this->normalizeLocations($wp['locations'] ?? null),
            ];
        }

        return [
            'id' => isset($data['id']) && is_string($data['id']) ? $data['id'] : null,
            'sku' => isset($data['sku']) && is_string($data['sku']) ? $data['sku'] : '',
            'name' => isset($data['name']) && is_string($data['name']) ? $data['name'] : null,
            'barcode' => isset($data['barcode']) && is_string($data['barcode']) ? $data['barcode'] : null,
            'warehouses' => $warehousesOut,
        ];
    }

    /**
     * @param  array<string, mixed>  $warehouse
     */
    private function warehouseDisplayName(array $warehouse): string
    {
        $id = isset($warehouse['identifier']) && is_string($warehouse['identifier']) ? $warehouse['identifier'] : null;
        $co = isset($warehouse['company_name']) && is_string($warehouse['company_name']) ? $warehouse['company_name'] : null;

        if ($id !== null && $co !== null && $id !== $co) {
            return $id.' — '.$co;
        }

        return $id ?? $co ?? 'Warehouse';
    }

    /**
     * @param  mixed  $locations
     *
     * @return array<int, array{item_location_id: string, location_id: string, location_name: string|null, quantity: int}>
     */
    private function normalizeLocations($locations): array
    {
        $edges = null;
        if (is_array($locations)) {
            if (isset($locations['edges']) && is_array($locations['edges'])) {
                $edges = $locations['edges'];
            } elseif (isset($locations['data']['edges']) && is_array($locations['data']['edges'])) {
                $edges = $locations['data']['edges'];
            }
        }
        if (! is_array($edges)) {
            return [];
        }

        $out = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $node = $edge['node'] ?? null;
            if (! is_array($node)) {
                continue;
            }
            $itemLocId = isset($node['id']) && is_string($node['id']) ? $node['id'] : '';
            $locId = isset($node['location_id']) && is_string($node['location_id']) ? $node['location_id'] : '';
            if ($itemLocId === '' || $locId === '') {
                continue;
            }
            $qty = $node['quantity'] ?? 0;
            $qty = is_int($qty) ? $qty : (int) $qty;
            $loc = is_array($node['location'] ?? null) ? $node['location'] : [];
            $locName = isset($loc['name']) && is_string($loc['name']) ? $loc['name'] : null;

            $out[] = [
                'item_location_id' => $itemLocId,
                'location_id' => $locId,
                'location_name' => $locName,
                'quantity' => max(0, $qty),
            ];
        }

        return $out;
    }

    /**
     * @return array{customer_account_id: string|null}
     */
    private function customerAccountVariables(?string $customerAccountId): array
    {
        $id = is_string($customerAccountId) && trim($customerAccountId) !== ''
            ? trim($customerAccountId)
            : null;

        return ['customer_account_id' => $id];
    }

    private function looksLikeBarcode(string $term): bool
    {
        $normalized = $this->normalizeBarcodeTerm($term);

        return $normalized !== ''
            && ctype_digit($normalized)
            && strlen($normalized) >= 6;
    }

    private function normalizeBarcodeTerm(string $term): string
    {
        $normalized = preg_replace('/[\s-]+/', '', $term);

        return is_string($normalized)
            && $normalized !== ''
            ? $normalized
            : $term;
    }
}
