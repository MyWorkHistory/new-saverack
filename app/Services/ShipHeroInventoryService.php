<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
            $data = $this->safeProductFetch('shiphero.inventory.search.by_barcode_failed', $term, $customerAccountId, function () use ($barcodeTerm, $customerAccountId) {
                return $this->fetchProductByBarcode($barcodeTerm, $customerAccountId);
            });
            if ($data === null) {
                $data = $this->safeProductFetch('shiphero.inventory.search.by_barcode_basic_failed', $term, $customerAccountId, function () use ($barcodeTerm, $customerAccountId) {
                    return $this->fetchProductByBarcodeBasic($barcodeTerm, $customerAccountId);
                });
            }
            if ($data === null) {
                $data = $this->safeProductFetch('shiphero.inventory.search.by_sku_after_barcode_failed', $term, $customerAccountId, function () use ($term, $customerAccountId) {
                    return $this->fetchProductBySku($term, $customerAccountId);
                });
            }
            if ($data === null) {
                $data = $this->safeProductFetch('shiphero.inventory.search.by_sku_basic_after_barcode_failed', $term, $customerAccountId, function () use ($term, $customerAccountId) {
                    return $this->fetchProductBySkuBasic($term, $customerAccountId);
                });
            }
        } else {
            $data = $this->safeProductFetch('shiphero.inventory.search.by_sku_failed', $term, $customerAccountId, function () use ($term, $customerAccountId) {
                return $this->fetchProductBySku($term, $customerAccountId);
            });
            if ($data === null) {
                $data = $this->safeProductFetch('shiphero.inventory.search.by_sku_basic_failed', $term, $customerAccountId, function () use ($term, $customerAccountId) {
                    return $this->fetchProductBySkuBasic($term, $customerAccountId);
                });
            }
            if ($data === null) {
                $data = $this->safeProductFetch('shiphero.inventory.search.by_barcode_after_sku_failed', $term, $customerAccountId, function () use ($term, $customerAccountId) {
                    return $this->fetchProductByBarcode($term, $customerAccountId);
                });
            }
            if ($data === null) {
                $data = $this->safeProductFetch('shiphero.inventory.search.by_barcode_basic_after_sku_failed', $term, $customerAccountId, function () use ($term, $customerAccountId) {
                    return $this->fetchProductByBarcodeBasic($term, $customerAccountId);
                });
            }
        }

        if ($data === null) {
            return null;
        }

        $id = isset($data['id']) && is_string($data['id']) ? trim($data['id']) : '';
        if ($id !== '') {
            try {
                $byId = $this->fetchProductById($id, $customerAccountId);
                if (is_array($byId)) {
                    $data = array_merge($data, $byId);
                }
            } catch (\Throwable $e) {
                // Keep SKU/barcode flow resilient if by-id detail query fails.
            }
        }

        return $this->normalizeProduct($data, $warehouseId);
    }

    /**
     * @param callable(): (array<string,mixed>|null) $fetcher
     * @return array<string,mixed>|null
     */
    private function safeProductFetch(string $logEvent, string $term, ?string $customerAccountId, callable $fetcher): ?array
    {
        try {
            $data = $fetcher();
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::warning($logEvent, [
                'term' => $term,
                'customer_account_id' => $customerAccountId,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProductBySkuBasic(string $sku, ?string $customerAccountId): ?array
    {
        if (! is_string($customerAccountId) || trim($customerAccountId) === '') {
            $graphqlNoCustomer = <<<'GQL'
query ShipHeroProductBySkuBasic($sku: String!) {
  product(sku: $sku) {
    data {
      id
      sku
      name
      barcode
      customs_value
      customs_description
      dimensions {
        weight
        height
        width
        length
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        reserve_inventory
        inventory_bin
        inventory_overstock_bin
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
            $json = $this->client->query($graphqlNoCustomer, ['sku' => $sku]);
            $data = data_get($json, 'data.product.data');
            return is_array($data) ? $data : null;
        }
        $graphql = <<<'GQL'
query ShipHeroProductBySkuBasic($sku: String!, $customer_account_id: String) {
  product(sku: $sku, customer_account_id: $customer_account_id) {
    data {
      id
      sku
      name
      barcode
      customs_value
      customs_description
      dimensions {
        weight
        height
        width
        length
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        reserve_inventory
        inventory_bin
        inventory_overstock_bin
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
     * @return array<string, mixed>|null
     */
    private function fetchProductByBarcodeBasic(string $barcode, ?string $customerAccountId): ?array
    {
        if (! is_string($customerAccountId) || trim($customerAccountId) === '') {
            $graphqlNoCustomer = <<<'GQL'
query ShipHeroProductByBarcodeBasic($barcode: String!) {
  product(barcode: $barcode) {
    data {
      id
      sku
      name
      barcode
      customs_value
      customs_description
      dimensions {
        weight
        height
        width
        length
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        reserve_inventory
        inventory_bin
        inventory_overstock_bin
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
            $json = $this->client->query($graphqlNoCustomer, ['barcode' => $barcode]);
            $data = data_get($json, 'data.product.data');
            return is_array($data) ? $data : null;
        }
        $graphql = <<<'GQL'
query ShipHeroProductByBarcodeBasic($barcode: String!, $customer_account_id: String) {
  product(barcode: $barcode, customer_account_id: $customer_account_id) {
    data {
      id
      sku
      name
      barcode
      customs_value
      customs_description
      dimensions {
        weight
        height
        width
        length
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        reserve_inventory
        inventory_bin
        inventory_overstock_bin
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
            'locations' => $this->normalizeLocations($wp['locations'] ?? null, $wid),
        ];
    }

    /**
     * @param string|null $customerAccountId
     * @return list<array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}>
     */
    public function listLocations(string $warehouseId, ?string $customerAccountId = null): array
    {
        $warehouseId = trim($warehouseId);
        if ($warehouseId === '') {
            return [];
        }
        $queries = [
            [
                'graphql' => <<<'GQL'
query ShipHeroLocationsByWarehouse($warehouse_id: String!, $customer_account_id: String) {
  locations(warehouse_id: $warehouse_id, customer_account_id: $customer_account_id) {
    data {
      edges {
        node {
          id
          name
          zone
          type {
            name
          }
          pickable
          sellable
        }
      }
    }
  }
}
GQL,
                'vars' => array_merge(['warehouse_id' => $warehouseId], $this->customerAccountVariables($customerAccountId)),
            ],
            [
                'graphql' => <<<'GQL'
query ShipHeroLocationsByWarehouseNoCustomer($warehouse_id: String!) {
  locations(warehouse_id: $warehouse_id) {
    data {
      edges {
        node {
          id
          name
          zone
          type {
            name
          }
          pickable
          sellable
        }
      }
    }
  }
}
GQL,
                'vars' => ['warehouse_id' => $warehouseId],
            ],
            [
                'graphql' => <<<'GQL'
query ShipHeroLocationsByWarehouseScalarType($warehouse_id: String!) {
  locations(warehouse_id: $warehouse_id) {
    data {
      edges {
        node {
          id
          name
          zone
          type
          pickable
          sellable
        }
      }
    }
  }
}
GQL,
                'vars' => ['warehouse_id' => $warehouseId],
            ],
        ];
        $json = null;
        $lastError = null;
        foreach ($queries as $candidate) {
            try {
                $json = $this->client->query($candidate['graphql'], $candidate['vars']);
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }
        if (! is_array($json)) {
            throw new RuntimeException($lastError instanceof \Throwable ? $lastError->getMessage() : 'Could not load locations.');
        }
        $edges = data_get($json, 'data.locations.data.edges');
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
            $id = trim((string) ($node['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $name = trim((string) ($node['name'] ?? ''));
            $typeName = trim((string) data_get($node, 'type.name', ''));
            if ($typeName === '') {
                $typeName = trim((string) ($node['type'] ?? ''));
            }
            $pickableRaw = array_key_exists('pickable', $node)
                ? $node['pickable']
                : (array_key_exists('is_pickable', $node) ? $node['is_pickable'] : null);
            $pickable = null;
            if (is_bool($pickableRaw)) {
                $pickable = $pickableRaw;
            } elseif (is_int($pickableRaw) || is_float($pickableRaw)) {
                $pickable = ((int) $pickableRaw) === 1;
            } elseif (is_string($pickableRaw)) {
                $normalizedPickable = strtolower(trim($pickableRaw));
                if (in_array($normalizedPickable, ['1', 'true', 'yes'], true)) {
                    $pickable = true;
                } elseif (in_array($normalizedPickable, ['0', 'false', 'no'], true)) {
                    $pickable = false;
                }
            }
            $out[] = [
                'id' => $id,
                'name' => $name,
                'type' => $typeName !== '' ? $typeName : null,
                'pickable' => $pickable,
                'sellable' => array_key_exists('sellable', $node) ? (bool) $node['sellable'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param string|null $customerAccountId
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}|null
     */
    public function resolveWarehouseLocation(string $warehouseId, string $locationInput, ?string $customerAccountId = null): ?array
    {
        $needle = trim($locationInput);
        if ($needle === '') {
            return null;
        }
        $locations = $this->listLocations($warehouseId, $customerAccountId);
        if ($locations === []) {
            return null;
        }
        foreach ($locations as $loc) {
            if (strcasecmp($loc['id'], $needle) === 0) {
                return $loc;
            }
        }
        foreach ($locations as $loc) {
            if (strcasecmp($loc['name'], $needle) === 0) {
                return $loc;
            }
        }
        return null;
    }

    /**
     * @param string|null $customerAccountId
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}
     */
    public function updateLocationPickable(
        string $locationId,
        bool $pickable,
        ?bool $sellable = null,
        ?string $customerAccountId = null
    ): array {
        $locationId = trim($locationId);
        if ($locationId === '') {
            throw new RuntimeException('location_id is required.');
        }
        $input = [
            'location_id' => $locationId,
            'pickable' => $pickable,
        ];
        if ($sellable !== null) {
            $input['sellable'] = $sellable;
        }
        if (is_string($customerAccountId) && trim($customerAccountId) !== '') {
            $input['customer_account_id'] = trim($customerAccountId);
        }
        $attemptInputs = [];
        // Try a few payload shapes because accounts can differ on accepted fields.
        $attemptInputs[] = ['location_id' => $locationId, 'pickable' => $pickable];
        if ($sellable !== null) {
            $attemptInputs[] = ['location_id' => $locationId, 'pickable' => $pickable, 'sellable' => $sellable];
        }
        if (is_string($customerAccountId) && trim($customerAccountId) !== '') {
            $withCustomer = ['location_id' => $locationId, 'pickable' => $pickable, 'customer_account_id' => trim($customerAccountId)];
            $attemptInputs[] = $withCustomer;
            if ($sellable !== null) {
                $withCustomer['sellable'] = $sellable;
                $attemptInputs[] = $withCustomer;
            }
        }
        $json = null;
        $lastError = null;
        foreach ($attemptInputs as $candidateInput) {
            try {
                $json = $this->client->query($this->buildLocationUpdateMutationLiteral($candidateInput));
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }
        if (! is_array($json)) {
            throw new RuntimeException($lastError instanceof \Throwable ? $lastError->getMessage() : 'Could not update location.');
        }
        $node = data_get($json, 'data.location_update.location');
        if (! is_array($node)) {
            throw new RuntimeException('ShipHero did not return updated location.');
        }
        $id = trim((string) ($node['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('ShipHero location_update response is missing id.');
        }
        $name = trim((string) ($node['name'] ?? ''));
        $typeName = null;
        try {
            $typeLookup = <<<'GQL'
query ShipHeroLocationById($id: String!) {
  location(id: $id) {
    data {
      type {
        name
      }
    }
  }
}
GQL;
            $typeJson = $this->client->query($typeLookup, ['id' => $id]);
            $typeRaw = trim((string) data_get($typeJson, 'data.location.data.type.name', ''));
            if ($typeRaw !== '') {
                $typeName = $typeRaw;
            }
        } catch (\Throwable $e) {
            // Some accounts expose location.type as scalar.
            try {
                $typeLookupScalar = <<<'GQL'
query ShipHeroLocationByIdScalar($id: String!) {
  location(id: $id) {
    data {
      type
    }
  }
}
GQL;
                $typeJson = $this->client->query($typeLookupScalar, ['id' => $id]);
                $typeRaw = trim((string) data_get($typeJson, 'data.location.data.type', ''));
                if ($typeRaw !== '') {
                    $typeName = $typeRaw;
                }
            } catch (\Throwable $ignored) {
                $typeName = null;
            }
        }
        return [
            'id' => $id,
            'name' => $name,
            'type' => $typeName !== null && trim($typeName) !== '' ? trim($typeName) : null,
            'pickable' => array_key_exists('pickable', $node) ? (bool) $node['pickable'] : null,
            'sellable' => array_key_exists('sellable', $node) ? (bool) $node['sellable'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $input
     */
    private function buildLocationUpdateMutationLiteral(array $input): string
    {
        $parts = [];
        foreach ($input as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }
            $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
            if (! is_string($safeKey) || $safeKey === '') {
                continue;
            }
            if (is_bool($value)) {
                $parts[] = $safeKey.': '.($value ? 'true' : 'false');
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $parts[] = $safeKey.': '.$value;
                continue;
            }
            $stringValue = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
            $parts[] = $safeKey.': "'.$stringValue.'"';
        }
        $dataLiteral = implode("\n    ", $parts);
        return 'mutation ShipHeroLocationUpdate {'."\n"
            .'  location_update(data: {'."\n"
            .'    '.$dataLiteral."\n"
            .'  }) {'."\n"
            .'    location {'."\n"
            .'      id'."\n"
            .'      name'."\n"
            .'      pickable'."\n"
            .'      sellable'."\n"
            .'    }'."\n"
            .'  }'."\n"
            .'}';
    }

    /**
     * @return array<string, mixed>|null  product.data
     */
    private function fetchProductBySku(string $sku, ?string $customerAccountId): ?array
    {
        if (! is_string($customerAccountId) || trim($customerAccountId) === '') {
            $graphqlNoCustomer = <<<'GQL'
query ShipHeroProductBySku($sku: String!) {
  product(sku: $sku) {
    data {
      id
      legacy_id
      account_id
      sku
      name
      barcode
      customs_value
      customs_description
      images {
        src
        position
      }
      dimensions {
        weight
        height
        width
        length
      }
      kit_components {
        quantity
        sku
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        inventory_bin
        inventory_overstock_bin
        reserve_inventory
        non_sellable_quantity
        in_tote
        reorder_level
        reorder_amount
        replenishment_level
        replenishment_max_level
        replenishment_increment
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
            $json = $this->client->query($graphqlNoCustomer, ['sku' => $sku]);
            $data = data_get($json, 'data.product.data');
            return is_array($data) ? $data : null;
        }
        $graphql = <<<'GQL'
query ShipHeroProductBySku($sku: String!, $customer_account_id: String) {
  product(sku: $sku, customer_account_id: $customer_account_id) {
    data {
      id
      legacy_id
      account_id
      sku
      name
      barcode
      customs_value
      customs_description
      images {
        src
        position
      }
      dimensions {
        weight
        height
        width
        length
      }
      kit_components {
        quantity
        sku
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        inventory_bin
        inventory_overstock_bin
        reserve_inventory
        non_sellable_quantity
        in_tote
        reorder_level
        reorder_amount
        replenishment_level
        replenishment_max_level
        replenishment_increment
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
        if (! is_string($customerAccountId) || trim($customerAccountId) === '') {
            $graphqlNoCustomer = <<<'GQL'
query ShipHeroProductByBarcode($barcode: String!) {
  product(barcode: $barcode) {
    data {
      id
      legacy_id
      account_id
      sku
      name
      barcode
      customs_value
      customs_description
      images {
        src
        position
      }
      dimensions {
        weight
        height
        width
        length
      }
      kit_components {
        quantity
        sku
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        inventory_bin
        inventory_overstock_bin
        reserve_inventory
        non_sellable_quantity
        in_tote
        reorder_level
        reorder_amount
        replenishment_level
        replenishment_max_level
        replenishment_increment
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
            $json = $this->client->query($graphqlNoCustomer, ['barcode' => $barcode]);
            $data = data_get($json, 'data.product.data');
            return is_array($data) ? $data : null;
        }
        $graphql = <<<'GQL'
query ShipHeroProductByBarcode($barcode: String!, $customer_account_id: String) {
  product(barcode: $barcode, customer_account_id: $customer_account_id) {
    data {
      id
      legacy_id
      account_id
      sku
      name
      barcode
      customs_value
      customs_description
      images {
        src
        position
      }
      dimensions {
        weight
        height
        width
        length
      }
      kit_components {
        quantity
        sku
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        inventory_bin
        inventory_overstock_bin
        reserve_inventory
        non_sellable_quantity
        in_tote
        reorder_level
        reorder_amount
        replenishment_level
        replenishment_max_level
        replenishment_increment
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
     * @return array<string, mixed>|null  product.data
     */
    private function fetchProductById(string $id, ?string $customerAccountId = null): ?array
    {
        $graphql = <<<'GQL'
query ShipHeroProductById($id: String!, $customer_account_id: String) {
  product(id: $id, customer_account_id: $customer_account_id) {
    data {
      id
      legacy_id
      account_id
      name
      sku
      price
      value
      barcode
      country_of_manufacture
      dimensions {
        weight
        height
        width
        length
      }
      tariff_code
      value_currency
      kit
      kit_build
      no_air
      final_sale
      customs_value
      customs_description
      not_owned
      dropship
      created_at
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        inventory_bin
        inventory_overstock_bin
        reserve_inventory
        non_sellable_quantity
        in_tote
        reorder_level
        reorder_amount
        replenishment_level
        replenishment_max_level
        replenishment_increment
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
      images {
        src
        position
      }
      tags
      vendors {
        vendor_id
        vendor_sku
      }
      product_note
      virtual
      ignore_on_invoice
      ignore_on_customs
      active
      kit_components {
        quantity
        sku
      }
    }
  }
}
GQL;
        $json = $this->client->query($graphql, array_merge(
            ['id' => $id],
            $this->customerAccountVariables($customerAccountId)
        ));
        $data = data_get($json, 'data.product.data');
        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeProduct(array $data, ?string $warehouseFilter): array
    {
        $warehousesOut = [];
        $onHand = 0;
        $allocated = 0;
        $backorder = 0;
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
            $warehouseOnHand = $this->toIntNumber($wp['on_hand'] ?? 0);
            $warehouseAllocated = $this->toIntNumber(
                $wp['allocated'] ?? ($wp['reserve_inventory'] ?? 0)
            );
            $warehouseBackorder = $this->toIntNumber($wp['backorder'] ?? 0);
            $onHand += max(0, $warehouseOnHand);
            $allocated += max(0, $warehouseAllocated);
            $backorder += max(0, $warehouseBackorder);
            $normalizedLocations = $this->normalizeLocations($wp['locations'] ?? null, $wid);
            if ($normalizedLocations === []) {
                $normalizedLocations = $this->fallbackLocationsFromWarehouseProduct($wp, $wid);
            }
            $warehousesOut[] = [
                'warehouse_id' => $wid,
                'warehouse_name' => $this->warehouseDisplayNameWithFallback($wh, $wp),
                'on_hand' => max(0, $warehouseOnHand),
                'allocated' => max(0, $warehouseAllocated),
                'backorder' => max(0, $warehouseBackorder),
                'locations' => $normalizedLocations,
            ];
        }

        $dimensions = is_array($data['dimensions'] ?? null) ? $data['dimensions'] : [];
        $imageUrl = null;
        $images = is_array($data['images'] ?? null) ? $data['images'] : [];
        $bestPos = PHP_INT_MAX;
        foreach ($images as $img) {
            if (! is_array($img)) {
                continue;
            }
            $src = trim((string) ($img['src'] ?? ''));
            if ($src === '') {
                continue;
            }
            $pos = isset($img['position']) && is_numeric($img['position']) ? (int) $img['position'] : 999999;
            if ($imageUrl === null || $pos < $bestPos) {
                $imageUrl = $src;
                $bestPos = $pos;
            }
        }
        $customsCandidates = [
            $data['customs_value'] ?? null,
            data_get($data, 'customs.value'),
            data_get($data, 'customs.customs_value'),
            data_get($data, 'customs.amount'),
            $data['customsValue'] ?? null,
            $data['custom_value'] ?? null,
            $data['value'] ?? null,
        ];
        $customsValue = null;
        foreach ($customsCandidates as $candidate) {
            $normalizedCandidate = $this->normalizeNumericDisplay($candidate);
            $candidateNumeric = is_numeric($normalizedCandidate) ? (float) $normalizedCandidate : null;
            if ($candidateNumeric !== null && $candidateNumeric > 0) {
                $customsValue = $normalizedCandidate;
                break;
            }
            if ($customsValue === null) {
                $customsValue = $normalizedCandidate;
            }
        }
        if ($customsValue === null) {
            $customsValue = 0.0;
        }
        $customsDescription = isset($data['customs_description']) && is_string($data['customs_description'])
            ? trim($data['customs_description'])
            : '';
        if ($customsDescription === '') {
            $fallbackDescription = isset($data['product_note']) && is_string($data['product_note'])
                ? trim($data['product_note'])
                : '';
            $customsDescription = $fallbackDescription;
        }

        return [
            'id' => isset($data['id']) && is_string($data['id']) ? $data['id'] : null,
            'sku' => isset($data['sku']) && is_string($data['sku']) ? $data['sku'] : '',
            'name' => isset($data['name']) && is_string($data['name']) ? $data['name'] : null,
            'barcode' => isset($data['barcode']) && is_string($data['barcode']) ? $data['barcode'] : null,
            'image_url' => $imageUrl,
            'customs_value' => $customsValue,
            'customs_description' => $customsDescription !== '' ? $customsDescription : null,
            'dimensions' => [
                'weight' => $this->normalizeNumericDisplay($dimensions['weight'] ?? null),
                'height' => $this->normalizeNumericDisplay($dimensions['height'] ?? null),
                'width' => $this->normalizeNumericDisplay($dimensions['width'] ?? null),
                'length' => $this->normalizeNumericDisplay($dimensions['length'] ?? null),
            ],
            'metrics' => [
                'on_hand' => $onHand,
                'allocated' => $allocated,
                'available' => max(0, $onHand - $allocated),
                'backorder' => $backorder,
                'asn' => 0,
            ],
            'kit_components' => $this->normalizeKitComponents($data['kit_components'] ?? null),
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
     * @param array<string,mixed> $warehouse
     * @param array<string,mixed> $warehouseProduct
     */
    private function warehouseDisplayNameWithFallback(array $warehouse, array $warehouseProduct): string
    {
        $fromWarehouse = $this->warehouseDisplayName($warehouse);
        if ($fromWarehouse !== 'Warehouse') {
            return $fromWarehouse;
        }
        $identifier = trim((string) ($warehouseProduct['warehouse_identifier'] ?? ''));
        if ($identifier !== '') {
            return $identifier;
        }

        return 'Warehouse';
    }

    /**
     * @param  mixed  $locations
     *
     * @return array<int, array{
     *  item_location_id:string,
     *  location_id:string,
     *  location_name:string|null,
     *  quantity:int,
     *  pickable:bool|null,
     *  type:string|null,
     *  warehouse_id:string|null
     * }>
     */
    private function normalizeLocations($locations, ?string $warehouseId = null): array
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
            $pickable = null;
            if (array_key_exists('pickable', $loc)) {
                $pickable = (bool) $loc['pickable'];
            }
            $type = isset($loc['type']) && is_string($loc['type']) && trim($loc['type']) !== ''
                ? trim($loc['type'])
                : $this->extractLocationTypeLabel($locName);

            $out[] = [
                'item_location_id' => $itemLocId,
                'location_id' => $locId,
                'location_name' => $locName,
                'quantity' => max(0, $qty),
                'pickable' => $pickable,
                'type' => $type,
                'warehouse_id' => $warehouseId,
            ];
        }

        return $out;
    }

    /**
     * Build fallback location rows from `inventory_bin` / `inventory_overstock_bin`
     * when ShipHero does not return `locations.edges`.
     *
     * @param array<string,mixed> $warehouseProduct
     * @return list<array{
     *  item_location_id:string,
     *  location_id:string,
     *  location_name:string|null,
     *  quantity:int,
     *  pickable:bool|null,
     *  type:string|null,
     *  warehouse_id:string|null
     * }>
     */
    private function fallbackLocationsFromWarehouseProduct(array $warehouseProduct, ?string $warehouseId = null): array
    {
        $out = [];
        $bin = trim((string) ($warehouseProduct['inventory_bin'] ?? ''));
        $overstock = trim((string) ($warehouseProduct['inventory_overstock_bin'] ?? ''));
        $onHand = max(0, (int) ($warehouseProduct['on_hand'] ?? 0));

        if ($bin !== '') {
            $out[] = [
                'item_location_id' => 'fallback:bin:'.$bin,
                'location_id' => $bin,
                'location_name' => $bin,
                'quantity' => $onHand,
                'pickable' => true,
                'type' => $this->extractLocationTypeLabel($bin),
                'warehouse_id' => $warehouseId,
            ];
        }
        if ($overstock !== '' && $overstock !== $bin) {
            $out[] = [
                'item_location_id' => 'fallback:overstock:'.$overstock,
                'location_id' => $overstock,
                'location_name' => $overstock,
                'quantity' => 0,
                'pickable' => false,
                'type' => $this->extractLocationTypeLabel($overstock),
                'warehouse_id' => $warehouseId,
            ];
        }

        return $out;
    }

    /**
     * @param mixed $components
     * @return list<array{sku:string,quantity:float}>
     */
    private function normalizeKitComponents($components): array
    {
        if (! is_array($components)) {
            return [];
        }
        $out = [];
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            $sku = trim((string) ($component['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $out[] = [
                'sku' => $sku,
                'quantity' => (float) ($component['quantity'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @param mixed $value
     */
    private function toIntNumber($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        if (is_string($value)) {
            $clean = preg_replace('/[^0-9.\-]/', '', $value);
            if (is_string($clean) && $clean !== '' && is_numeric($clean)) {
                return (int) round((float) $clean);
            }
        }
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }
        return 0;
    }

    /**
     * @param mixed $value
     * @return float|string|null
     */
    private function normalizeNumericDisplay($value)
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $raw = trim($value);
            if ($raw === '') {
                return null;
            }
            $clean = preg_replace('/[^0-9.\-]/', '', $raw);
            if (is_string($clean) && $clean !== '' && is_numeric($clean)) {
                return (float) $clean;
            }
            return $raw;
        }
        return null;
    }

    private function extractLocationTypeLabel(?string $locationName): ?string
    {
        $source = trim((string) $locationName);
        if ($source === '') {
            return null;
        }
        if (preg_match('/\\b(bin|pallet|shelf)\\s*\\(\\s*(small|medium|large|x-?large)\\s*\\)/i', $source, $m) === 1) {
            $base = ucfirst(strtolower((string) $m[1]));
            $size = strtolower((string) $m[2]);
            $normalizedSize = $size === 'xlarge' || $size === 'x-large' ? 'X-Large' : ucfirst($size);
            return $base.' ('.$normalizedSize.')';
        }
        if (preg_match('/\\bcustom\\b/i', $source) === 1) return 'Custom';
        if (preg_match('/\\bsleeve\\b/i', $source) === 1) return 'Sleeve';
        return null;
    }

    /**
     * @param string|null $customerAccountId
     * @return array<string,mixed>|null
     */
    public function getProductDetailBySku(string $sku, ?string $warehouseId = null, ?string $customerAccountId = null): ?array
    {
        $base = null;
        try {
            $base = $this->fetchProductBySku(trim($sku), $customerAccountId);
        } catch (\Throwable $e) {
            Log::warning('shiphero.inventory.detail.by_sku_failed', [
                'sku' => $sku,
                'customer_account_id' => $customerAccountId,
                'message' => $e->getMessage(),
            ]);
        }
        if ($base === null) {
            try {
                $base = $this->fetchProductByBarcode(trim($sku), $customerAccountId);
            } catch (\Throwable $e) {
                Log::warning('shiphero.inventory.detail.by_barcode_failed', [
                    'sku_or_barcode' => $sku,
                    'customer_account_id' => $customerAccountId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        if ($base === null) {
            return null;
        }
        $id = isset($base['id']) && is_string($base['id']) ? trim($base['id']) : '';
        if ($id === '') {
            return $this->normalizeProduct($base, $warehouseId);
        }
        try {
            $full = $this->fetchProductById($id, $customerAccountId);
            if (is_array($full)) {
                $merged = array_merge($base, $full);
                // ShipHero can return 0 for customs_value on product(id) while SKU/barcode
                // responses still contain the real customs value. Preserve the non-zero value.
                $merged['customs_value'] = $this->pickPreferredPositiveNumeric(
                    $full['customs_value'] ?? null,
                    $base['customs_value'] ?? null
                );
                $merged['customsValue'] = $this->pickPreferredPositiveNumeric(
                    $full['customsValue'] ?? null,
                    $base['customsValue'] ?? null
                );
                $merged['custom_value'] = $this->pickPreferredPositiveNumeric(
                    $full['custom_value'] ?? null,
                    $base['custom_value'] ?? null
                );
                $merged['warehouse_products'] = $this->pickWarehouseProductsPayload(
                    $base['warehouse_products'] ?? null,
                    $full['warehouse_products'] ?? null
                );
                $normalized = $this->normalizeProduct($merged, $warehouseId);
                Log::info('shiphero.inventory.detail.normalized', [
                    'sku' => $normalized['sku'] ?? null,
                    'customer_account_id' => $customerAccountId,
                    'customs_value' => $normalized['customs_value'] ?? null,
                    'customs_description' => $normalized['customs_description'] ?? null,
                    'metrics' => $normalized['metrics'] ?? null,
                    'source' => 'product_by_id',
                    'raw_customs' => [
                        'base_customs_value' => $base['customs_value'] ?? null,
                        'full_customs_value' => $full['customs_value'] ?? null,
                        'base_customsValue' => $base['customsValue'] ?? null,
                        'full_customsValue' => $full['customsValue'] ?? null,
                        'base_custom_value' => $base['custom_value'] ?? null,
                        'full_custom_value' => $full['custom_value'] ?? null,
                    ],
                ]);
                return $this->enrichProductLocationsMeta($normalized, $customerAccountId);
            }
        } catch (\Throwable $e) {
            Log::warning('shiphero.inventory.detail.by_id_failed_fallback', [
                'sku' => $sku,
                'product_id' => $id,
                'customer_account_id' => $customerAccountId,
                'message' => $e->getMessage(),
            ]);
        }

        $normalized = $this->normalizeProduct($base, $warehouseId);
        Log::info('shiphero.inventory.detail.normalized', [
            'sku' => $normalized['sku'] ?? null,
            'customer_account_id' => $customerAccountId,
            'customs_value' => $normalized['customs_value'] ?? null,
            'customs_description' => $normalized['customs_description'] ?? null,
            'metrics' => $normalized['metrics'] ?? null,
            'source' => 'product_by_sku_or_barcode',
        ]);
        return $this->enrichProductLocationsMeta($normalized, $customerAccountId);
    }

    /**
     * @param mixed $base
     * @param mixed $full
     * @return array<int, array<string, mixed>>
     */
    private function pickWarehouseProductsPayload($base, $full): array
    {
        $baseRows = is_array($base) ? $base : [];
        $fullRows = is_array($full) ? $full : [];
        if ($baseRows === []) return $fullRows;
        if ($fullRows === []) return $baseRows;

        return $this->warehouseProductsCompletenessScore($baseRows) >= $this->warehouseProductsCompletenessScore($fullRows)
            ? $baseRows
            : $fullRows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function warehouseProductsCompletenessScore(array $rows): int
    {
        $score = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['on_hand', 'allocated', 'reserve_inventory', 'backorder', 'reorder_amount', 'reorder_level', 'replenishment_level'] as $key) {
                if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                    $score++;
                }
            }
        }

        return $score;
    }

    /**
     * @param mixed $primary
     * @param mixed $fallback
     * @return mixed
     */
    private function pickPreferredPositiveNumeric($primary, $fallback)
    {
        $primaryNormalized = $this->normalizeNumericDisplay($primary);
        $primaryNumeric = is_numeric($primaryNormalized) ? (float) $primaryNormalized : null;
        if ($primaryNumeric !== null && $primaryNumeric > 0) {
            return $primary;
        }
        $fallbackNormalized = $this->normalizeNumericDisplay($fallback);
        $fallbackNumeric = is_numeric($fallbackNormalized) ? (float) $fallbackNormalized : null;
        if ($fallbackNumeric !== null && $fallbackNumeric > 0) {
            return $fallback;
        }
        return $primary !== null ? $primary : $fallback;
    }

    /**
     * Transfer quantity between two locations by issuing two replace mutations.
     *
     * @return array<string,mixed>
     */
    public function transferLocationQuantity(
        string $sku,
        string $warehouseId,
        string $fromLocationId,
        string $toLocationId,
        int $quantity,
        string $reason,
        ?string $customerAccountId = null
    ): array {
        if ($quantity <= 0) {
            throw new RuntimeException('Transfer quantity must be greater than zero.');
        }
        if ($fromLocationId === $toLocationId) {
            throw new RuntimeException('Source and destination locations must be different.');
        }
        $product = $this->searchProduct($sku, $warehouseId, $customerAccountId);
        if (! is_array($product)) {
            throw new RuntimeException('Product not found for transfer.');
        }
        $warehouse = null;
        foreach (($product['warehouses'] ?? []) as $wh) {
            if (is_array($wh) && (string) ($wh['warehouse_id'] ?? '') === $warehouseId) {
                $warehouse = $wh;
                break;
            }
        }
        if (! is_array($warehouse)) {
            throw new RuntimeException('Warehouse not found for transfer.');
        }
        $fromQty = null;
        $toQty = 0;
        foreach (($warehouse['locations'] ?? []) as $loc) {
            if (! is_array($loc)) {
                continue;
            }
            if ((string) ($loc['location_id'] ?? '') === $fromLocationId) {
                $fromQty = (int) ($loc['quantity'] ?? 0);
            }
            if ((string) ($loc['location_id'] ?? '') === $toLocationId) {
                $toQty = (int) ($loc['quantity'] ?? 0);
            }
        }
        if ($fromQty === null) {
            throw new RuntimeException('Source location not found.');
        }
        if ($fromQty < $quantity) {
            throw new RuntimeException('Transfer quantity exceeds source location quantity.');
        }

        $this->replaceLocationQuantity(
            $sku,
            $warehouseId,
            $fromLocationId,
            max(0, $fromQty - $quantity),
            $reason,
            $customerAccountId
        );

        return $this->replaceLocationQuantity(
            $sku,
            $warehouseId,
            $toLocationId,
            max(0, $toQty + $quantity),
            $reason,
            $customerAccountId
        );
    }

    /**
     * @param array<string,mixed> $normalized
     * @param string|null $customerAccountId
     * @return array<string,mixed>
     */
    private function enrichProductLocationsMeta(array $normalized, ?string $customerAccountId): array
    {
        $warehouses = $normalized['warehouses'] ?? null;
        if (! is_array($warehouses) || $warehouses === []) {
            return $normalized;
        }
        foreach ($warehouses as $wIndex => $warehouse) {
            if (! is_array($warehouse)) {
                continue;
            }
            $wid = trim((string) ($warehouse['warehouse_id'] ?? ''));
            if ($wid === '') {
                continue;
            }
            $catalogById = [];
            $catalogByName = [];
            try {
                foreach ($this->listLocations($wid, $customerAccountId) as $locationMeta) {
                    $idKey = strtolower(trim((string) ($locationMeta['id'] ?? '')));
                    if ($idKey !== '') {
                        $catalogById[$idKey] = $locationMeta;
                    }
                    $nameKey = strtolower(trim((string) ($locationMeta['name'] ?? '')));
                    if ($nameKey !== '') {
                        $catalogByName[$nameKey] = $locationMeta;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('shiphero.inventory.locations_meta_lookup_failed', [
                    'warehouse_id' => $wid,
                    'customer_account_id' => $customerAccountId,
                    'message' => $e->getMessage(),
                ]);
            }
            if ($catalogById === [] && $catalogByName === []) {
                continue;
            }
            $locations = $warehouse['locations'] ?? null;
            if (! is_array($locations)) {
                continue;
            }
            foreach ($locations as $lIndex => $location) {
                if (! is_array($location)) {
                    continue;
                }
                $locationId = strtolower(trim((string) ($location['location_id'] ?? '')));
                $locationName = strtolower(trim((string) ($location['location_name'] ?? '')));
                $meta = null;
                if ($locationId !== '' && isset($catalogById[$locationId])) {
                    $meta = $catalogById[$locationId];
                } elseif ($locationName !== '' && isset($catalogByName[$locationName])) {
                    $meta = $catalogByName[$locationName];
                }
                if (! is_array($meta)) {
                    continue;
                }
                if (is_bool($meta['pickable'])) {
                    $warehouse['locations'][$lIndex]['pickable'] = $meta['pickable'];
                }
                if (is_string($meta['type']) && trim($meta['type']) !== '') {
                    $warehouse['locations'][$lIndex]['type'] = trim($meta['type']);
                }
            }
            $normalized['warehouses'][$wIndex] = $warehouse;
        }
        return $normalized;
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
