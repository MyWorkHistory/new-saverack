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
            $explicitBackorder = $this->toIntNumber(
                $wp['backorder']
                ?? ($wp['reorder_amount']
                ?? ($wp['reorder_level']
                ?? ($wp['replenishment_level'] ?? 0)))
            );
            $warehouseBackorder = $explicitBackorder > 0
                ? $explicitBackorder
                : max(0, $warehouseAllocated - $warehouseOnHand);
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
        $customsValue = $this->normalizeNumericDisplay($data['customs_value'] ?? null);
        $customsNumeric = is_numeric($customsValue) ? (float) $customsValue : null;
        if ($customsValue === null || ($customsNumeric !== null && $customsNumeric <= 0)) {
            $customsValue = $this->normalizeNumericDisplay($data['value'] ?? null);
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
                ]);
                return $normalized;
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
        return $normalized;
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
