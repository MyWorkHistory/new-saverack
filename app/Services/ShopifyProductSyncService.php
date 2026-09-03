<?php

namespace App\Services;

use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Support\ShopifyGid;
use Carbon\Carbon;
use RuntimeException;

class ShopifyProductSyncService
{
    /** @var ShopifyClient */
    private $client;

    public function __construct(ShopifyClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return array{products:int, variants:int}
     */
    public function importActiveProducts(ClientAccountShopifyConnection $connection, ?ShopifyClient $api = null): array
    {
        $api = $api ?? $this->client->forConnection($connection);
        $products = 0;
        $variants = 0;
        $cursor = null;
        $page = 0;

        do {
            $page++;
            $data = $api->graphql(
                <<<'GQL'
query ActiveProducts($cursor: String) {
  products(first: 25, after: $cursor, query: "status:active") {
    pageInfo { hasNextPage endCursor }
    edges {
      node {
        id
        title
        handle
        status
        vendor
        productType
        updatedAt
        featuredImage { url altText }
        images(first: 3) {
          edges {
            node { url altText }
          }
        }
        variants(first: 100) {
          edges {
            node {
              id
              title
              sku
              barcode
              price
              image { url altText }
              inventoryItem { id measurement { weight { value unit } } }
              updatedAt
            }
          }
        }
      }
    }
  }
}
GQL
                ,
                ['cursor' => $cursor]
            );

            $conn = is_array($data['products'] ?? null) ? $data['products'] : [];
            foreach (($conn['edges'] ?? []) as $edge) {
                $node = is_array($edge['node'] ?? null) ? $edge['node'] : null;
                if ($node === null) {
                    continue;
                }
                $status = strtoupper((string) ($node['status'] ?? ''));
                if ($status !== '' && $status !== 'ACTIVE') {
                    continue;
                }
                $result = $this->upsertProductFromShopifyNode($connection, $node, true);
                $products += $result['product'] ? 1 : 0;
                $variants += $result['variants'];
            }

            $pageInfo = is_array($conn['pageInfo'] ?? null) ? $conn['pageInfo'] : [];
            $cursor = ShopifyClient::nextPageCursor($cursor, $pageInfo, $page, 40);
        } while ($cursor !== null);

        return compact('products', 'variants');
    }

    /**
     * Webhook / incremental: create new, skip overwriting locked CRM fields.
     *
     * @param  array<string, mixed>  $node
     * @return array{product:bool, variants:int}
     */
    public function upsertProductFromShopifyNode(
        ClientAccountShopifyConnection $connection,
        array $node,
        bool $forceSnapshotFields = false
    ): array {
        $productId = ShopifyGid::toId((string) ($node['id'] ?? $node['admin_graphql_api_id'] ?? ''));
        if ($productId === '' && isset($node['id']) && is_numeric($node['id'])) {
            $productId = (string) $node['id'];
        }
        if ($productId === '') {
            return ['product' => false, 'variants' => 0];
        }

        $status = strtolower((string) ($node['status'] ?? 'active'));
        if (in_array($status, ['draft', 'archived'], true)) {
            return ['product' => false, 'variants' => 0];
        }

        /** @var ShopifyProduct $product */
        $product = ShopifyProduct::query()->firstOrNew([
            'connection_id' => $connection->id,
            'shopify_product_id' => $productId,
        ]);

        $isNew = ! $product->exists;
        $locked = $product->exists && $product->isCrmLocked() && ! $forceSnapshotFields;

        if ($isNew || ! $locked) {
            $product->title = (string) ($node['title'] ?? $product->title);
            $product->handle = (string) ($node['handle'] ?? $product->handle);
            $product->vendor = (string) ($node['vendor'] ?? $product->vendor);
            $product->product_type = (string) ($node['productType'] ?? $node['product_type'] ?? $product->product_type);
        }
        $product->status = $status !== '' ? $status : ($product->status ?? 'active');
        $product->shopify_updated_at = $this->parseTime($node['updatedAt'] ?? $node['updated_at'] ?? null);
        $product->raw_json = $node;
        // crm_locked_at is set only when CRM pushes edits (see pushVariantToShopify).
        $product->save();

        $variantNodes = $this->extractVariantNodes($node);
        $variantCount = 0;
        foreach ($variantNodes as $variantNode) {
            if ($this->upsertVariantFromShopifyNode($connection, $product, $variantNode, $forceSnapshotFields)) {
                $variantCount++;
            }
        }

        return ['product' => true, 'variants' => $variantCount];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractShopifyProductId(array $payload): string
    {
        foreach (['admin_graphql_api_id', 'id'] as $key) {
            if (! isset($payload[$key]) || is_array($payload[$key])) {
                continue;
            }
            $id = ShopifyGid::toId((string) $payload[$key]);
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }

    public function deleteProductByShopifyId(ClientAccountShopifyConnection $connection, string $shopifyProductId): bool
    {
        $productId = ShopifyGid::toId($shopifyProductId);
        if ($productId === '') {
            return false;
        }

        $product = ShopifyProduct::query()
            ->where('connection_id', $connection->id)
            ->where('shopify_product_id', $productId)
            ->first();
        if ($product === null) {
            return false;
        }

        $inventoryItemIds = $product->variants()
            ->pluck('shopify_inventory_item_id')
            ->filter(static function ($id) {
                return $id !== null && $id !== '';
            })
            ->values()
            ->all();
        if ($inventoryItemIds !== []) {
            ShopifyInventoryLevel::query()
                ->where('connection_id', $connection->id)
                ->whereIn('shopify_inventory_item_id', $inventoryItemIds)
                ->delete();
        }

        $product->variants()->delete();
        $product->delete();

        return true;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function upsertVariantFromShopifyNode(
        ClientAccountShopifyConnection $connection,
        ShopifyProduct $product,
        array $node,
        bool $forceSnapshotFields = false
    ): bool {
        $variantId = ShopifyGid::toId((string) ($node['id'] ?? $node['admin_graphql_api_id'] ?? ''));
        if ($variantId === '' && isset($node['id']) && is_numeric($node['id'])) {
            $variantId = (string) $node['id'];
        }
        if ($variantId === '') {
            return false;
        }

        $inventoryItemId = '';
        if (is_array($node['inventoryItem'] ?? null)) {
            $inventoryItemId = ShopifyGid::toId((string) ($node['inventoryItem']['id'] ?? ''));
        } elseif (isset($node['inventory_item_id'])) {
            $inventoryItemId = ShopifyGid::toId((string) $node['inventory_item_id']);
        }

        /** @var ShopifyProductVariant $variant */
        $variant = ShopifyProductVariant::query()->firstOrNew([
            'connection_id' => $connection->id,
            'shopify_variant_id' => $variantId,
        ]);
        $isNew = ! $variant->exists;
        $locked = $variant->exists && $variant->isCrmLocked() && ! $forceSnapshotFields;

        $variant->shopify_product_id = $product->id;
        if ($inventoryItemId !== '') {
            $variant->shopify_inventory_item_id = $inventoryItemId;
        }

        if ($isNew || ! $locked) {
            $variant->title = (string) ($node['title'] ?? $variant->title);
            $variant->sku = (string) ($node['sku'] ?? $variant->sku);
            $variant->price = isset($node['price']) ? (float) $node['price'] : $variant->price;
        }

        if ($isNew) {
            $variant->barcode = (string) ($node['barcode'] ?? $variant->barcode);

            $weight = null;
            $weightUnit = null;
            if (is_array($node['inventoryItem']['measurement']['weight'] ?? null)) {
                $weight = $node['inventoryItem']['measurement']['weight']['value'] ?? null;
                $weightUnit = $node['inventoryItem']['measurement']['weight']['unit'] ?? null;
            } elseif (isset($node['weight'])) {
                $weight = $node['weight'];
                $weightUnit = $node['weight_unit'] ?? $node['weightUnit'] ?? null;
            }
            if ($weight !== null && $weight !== '') {
                $variant->weight = (float) $weight;
            }
            if ($weightUnit !== null && $weightUnit !== '') {
                $variant->weight_unit = (string) $weightUnit;
            }
        }

        $variant->shopify_updated_at = $this->parseTime($node['updatedAt'] ?? $node['updated_at'] ?? null);
        $variant->raw_json = $node;

        // Capture Shopify image once; never overwrite after first sync or CRM upload.
        if (trim((string) ($variant->synced_image_url ?? '')) === '') {
            $productRaw = is_array($product->raw_json) ? $product->raw_json : null;
            $fromShopify = \App\Support\ShopifyProductImage::url(
                is_array($node) ? $node : null,
                $productRaw
            );
            if (is_string($fromShopify) && trim($fromShopify) !== '') {
                $variant->synced_image_url = trim($fromShopify);
            }
        }

        // crm_locked_at is set only when CRM pushes edits (see pushVariantToShopify).
        $variant->save();

        return true;
    }

    /**
     * Push Active/Inactive product status to Shopify (ACTIVE / DRAFT).
     */
    public function pushProductStatusToShopify(ShopifyProduct $product, string $status): void
    {
        $connection = $product->connection;
        if ($connection === null || ! $connection->hasCredentials()) {
            return;
        }

        $shopifyStatus = strtolower(trim($status)) === 'inactive' ? 'DRAFT' : 'ACTIVE';
        $api = $this->client->forConnection($connection);
        $data = $api->graphql(
            <<<'GQL'
mutation productUpdateStatus($input: ProductInput!) {
  productUpdate(input: $input) {
    product { id status }
    userErrors { field message }
  }
}
GQL
            ,
            [
                'input' => [
                    'id' => ShopifyGid::of('Product', $product->shopify_product_id),
                    'status' => $shopifyStatus,
                ],
            ]
        );
        $this->assertNoUserErrors($data['productUpdate'] ?? null);
        $product->crm_locked_at = now();
        $product->save();
    }

    /**
     * Create a product (plus its default variant) in Shopify and mirror it into the CRM.
     *
     * @param  array{name:string, sku?:string|null, barcode?:string|null, weight?:float|null, weight_unit?:string|null, length?:float|null, width?:float|null, height?:float|null, dimension_unit?:string|null}  $fields
     */
    public function createProductWithVariant(
        ClientAccountShopifyConnection $connection,
        array $fields
    ): ShopifyProductVariant {
        if (! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection credentials missing.');
        }
        $title = trim((string) ($fields['name'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Product name is required.');
        }

        $api = $this->client->forConnection($connection);
        $data = $api->graphql(
            <<<'GQL'
mutation productCreate($input: ProductInput!) {
  productCreate(input: $input) {
    product {
      id
      title
      handle
      status
      vendor
      productType
      updatedAt
      variants(first: 1) {
        edges {
          node {
            id
            title
            sku
            barcode
            price
            inventoryItem { id }
            updatedAt
          }
        }
      }
    }
    userErrors { field message }
  }
}
GQL
            ,
            [
                'input' => [
                    'title' => $title,
                    'status' => 'ACTIVE',
                ],
            ]
        );
        $this->assertNoUserErrors($data['productCreate'] ?? null);

        $node = is_array($data['productCreate']['product'] ?? null) ? $data['productCreate']['product'] : [];
        if (ShopifyGid::toId((string) ($node['id'] ?? '')) === '') {
            throw new RuntimeException('Shopify did not return the new product.');
        }
        $this->upsertProductFromShopifyNode($connection, $node, true);

        $variantNodes = $this->extractVariantNodes($node);
        $variantId = ShopifyGid::toId((string) ($variantNodes[0]['id'] ?? ''));
        if ($variantId === '') {
            throw new RuntimeException('Shopify did not return a default variant.');
        }

        $variant = ShopifyProductVariant::query()
            ->with(['product', 'connection'])
            ->where('connection_id', $connection->id)
            ->where('shopify_variant_id', $variantId)
            ->first();
        if ($variant === null) {
            throw new RuntimeException('Could not store the new product locally.');
        }

        $push = [];
        foreach (['sku', 'barcode', 'weight', 'weight_unit', 'length', 'width', 'height', 'dimension_unit'] as $key) {
            if (array_key_exists($key, $fields) && $fields[$key] !== null && $fields[$key] !== '') {
                $push[$key] = $fields[$key];
            }
        }
        if ($push !== []) {
            $variant = $this->pushVariantToShopify($variant, $push);
        }

        return $variant;
    }

    /**
     * Push CRM-edited fields to Shopify.
     *
     * @param  array{title?:string|null, sku?:string|null, barcode?:string|null, weight?:float|null, weight_unit?:string|null, length?:float|null, width?:float|null, height?:float|null, dimension_unit?:string|null, product_title?:string|null}  $fields
     */
    public function pushVariantToShopify(ShopifyProductVariant $variant, array $fields): ShopifyProductVariant
    {
        $connection = $variant->connection;
        if ($connection === null || ! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection credentials missing.');
        }

        $api = $this->client->forConnection($connection);
        $product = $variant->product;
        if ($product === null) {
            throw new RuntimeException('Variant product missing.');
        }

        if (array_key_exists('product_title', $fields) && $fields['product_title'] !== null) {
            $title = trim((string) $fields['product_title']);
            $data = $api->graphql(
                <<<'GQL'
mutation productUpdate($input: ProductInput!) {
  productUpdate(input: $input) {
    product { id title }
    userErrors { field message }
  }
}
GQL
                ,
                [
                    'input' => [
                        'id' => ShopifyGid::of('Product', $product->shopify_product_id),
                        'title' => $title,
                    ],
                ]
            );
            $this->assertNoUserErrors($data['productUpdate'] ?? null);
            $product->title = $title;
            $product->crm_locked_at = now();
            $product->save();
        }

        $variantInput = [
            'id' => ShopifyGid::of('ProductVariant', $variant->shopify_variant_id),
        ];
        if (array_key_exists('title', $fields) && $fields['title'] !== null) {
            $variantInput['title'] = trim((string) $fields['title']);
        }
        if (array_key_exists('sku', $fields) && $fields['sku'] !== null) {
            $variantInput['inventoryItem'] = array_merge(
                $variantInput['inventoryItem'] ?? [],
                ['sku' => trim((string) $fields['sku'])]
            );
        }
        $barcode = array_key_exists('barcode', $fields) ? $fields['barcode'] : $variant->barcode;
        if ($barcode !== null && trim((string) $barcode) !== '') {
            $variantInput['barcode'] = trim((string) $barcode);
        }
        $weightValue = array_key_exists('weight', $fields) ? $fields['weight'] : $variant->weight;
        $weightUnitField = array_key_exists('weight_unit', $fields) ? $fields['weight_unit'] : $variant->weight_unit;
        if ($weightValue !== null && $weightValue !== '') {
            $unit = strtoupper((string) ($weightUnitField ?? 'POUNDS'));
            if (! in_array($unit, ['GRAMS', 'KILOGRAMS', 'OUNCES', 'POUNDS'], true)) {
                $unit = 'POUNDS';
            }
            $variantInput['inventoryItem'] = array_merge(
                $variantInput['inventoryItem'] ?? [],
                [
                    'measurement' => [
                        'weight' => [
                            'value' => (float) $weightValue,
                            'unit' => $unit,
                        ],
                    ],
                ]
            );
        }

        if (count($variantInput) > 1) {
            $data = $api->graphql(
                <<<'GQL'
mutation productVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    productVariants { id sku title }
    userErrors { field message }
  }
}
GQL
                ,
                [
                    'productId' => ShopifyGid::of('Product', $product->shopify_product_id),
                    'variants' => [$variantInput],
                ]
            );
            $this->assertNoUserErrors($data['productVariantsBulkUpdate'] ?? null);

            if (isset($variantInput['title'])) {
                $variant->title = $variantInput['title'];
            }
            if (isset($fields['sku'])) {
                $variant->sku = trim((string) $fields['sku']);
            }
            if (array_key_exists('barcode', $fields) && $fields['barcode'] !== null) {
                $variant->barcode = trim((string) $fields['barcode']);
            }
            if (isset($fields['weight'])) {
                $variant->weight = (float) $fields['weight'];
            }
            if (isset($fields['weight_unit'])) {
                $variant->weight_unit = (string) $fields['weight_unit'];
            }
            foreach (['length', 'width', 'height'] as $dimKey) {
                if (array_key_exists($dimKey, $fields) && $fields[$dimKey] !== null && $fields[$dimKey] !== '') {
                    $variant->{$dimKey} = (float) $fields[$dimKey];
                }
            }
            if (array_key_exists('dimension_unit', $fields) && $fields['dimension_unit'] !== null) {
                $variant->dimension_unit = (string) $fields['dimension_unit'];
            }
            $variant->crm_locked_at = now();
            $variant->save();
        } else {
            foreach (['length', 'width', 'height'] as $dimKey) {
                if (array_key_exists($dimKey, $fields) && $fields[$dimKey] !== null && $fields[$dimKey] !== '') {
                    $variant->{$dimKey} = (float) $fields[$dimKey];
                }
            }
            if (array_key_exists('dimension_unit', $fields) && $fields['dimension_unit'] !== null) {
                $variant->dimension_unit = (string) $fields['dimension_unit'];
            }
            $variant->save();
        }

        return $variant->fresh(['product']);
    }

    /**
     * Push CRM-owned quantities to Shopify for locations with sync_inventory=true.
     *
     * @param  list<array{location_id:string, available:int}>|null  $levels
     */
    public function pushInventoryToShopify(ShopifyProductVariant $variant, ?array $levels = null): int
    {
        $connection = $variant->connection;
        if ($connection === null || ! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection credentials missing.');
        }
        $itemId = trim((string) $variant->shopify_inventory_item_id);
        if ($itemId === '') {
            return 0;
        }

        $enabled = ShopifyLocation::query()
            ->where('connection_id', $connection->id)
            ->where('sync_inventory', true)
            ->pluck('shopify_location_id')
            ->map(static function ($id) {
                return (string) $id;
            })
            ->all();
        if ($enabled === []) {
            return 0;
        }

        if ($levels === null) {
            $rows = ShopifyInventoryLevel::query()
                ->where('connection_id', $connection->id)
                ->where('shopify_inventory_item_id', $itemId)
                ->whereNotNull('crm_set_at')
                ->get();
            $levels = [];
            foreach ($rows as $row) {
                $levels[] = [
                    'location_id' => (string) $row->shopify_location_id,
                    'available' => (int) $row->available,
                ];
            }
        }

        $quantities = [];
        foreach ($levels as $level) {
            $locationId = ShopifyGid::toId((string) ($level['location_id'] ?? ''));
            if ($locationId === '' || ! in_array($locationId, $enabled, true)) {
                continue;
            }
            if (! array_key_exists('available', $level) || $level['available'] === null) {
                continue;
            }
            $quantities[] = [
                'inventoryItemId' => ShopifyGid::of('InventoryItem', $itemId),
                'locationId' => ShopifyGid::of('Location', $locationId),
                'quantity' => (int) $level['available'],
            ];
        }
        if ($quantities === []) {
            return 0;
        }

        $api = $this->client->forConnection($connection);
        $data = $api->graphql(
            <<<'GQL'
mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
  inventorySetQuantities(input: $input) {
    userErrors { field message }
  }
}
GQL
            ,
            [
                'input' => [
                    'name' => 'available',
                    'reason' => 'correction',
                    'ignoreCompareQuantity' => true,
                    'quantities' => $quantities,
                ],
            ]
        );
        $this->assertNoUserErrors($data['inventorySetQuantities'] ?? null);

        return count($quantities);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private function extractVariantNodes(array $node): array
    {
        if (isset($node['variants']['edges']) && is_array($node['variants']['edges'])) {
            $out = [];
            foreach ($node['variants']['edges'] as $edge) {
                if (is_array($edge['node'] ?? null)) {
                    $out[] = $edge['node'];
                }
            }

            return $out;
        }

        // REST webhook product payload
        if (isset($node['variants']) && is_array($node['variants'])) {
            $out = [];
            foreach ($node['variants'] as $variant) {
                if (is_array($variant)) {
                    $out[] = $variant;
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * @param  mixed  $payload
     */
    private function assertNoUserErrors($payload): void
    {
        if (! is_array($payload)) {
            throw new RuntimeException('Shopify mutation returned no payload.');
        }
        $errors = is_array($payload['userErrors'] ?? null) ? $payload['userErrors'] : [];
        if ($errors !== []) {
            throw new RuntimeException((string) ($errors[0]['message'] ?? 'Shopify mutation failed.'));
        }
    }

    private function parseTime($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
