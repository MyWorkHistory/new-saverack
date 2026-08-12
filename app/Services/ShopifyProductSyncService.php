<?php

namespace App\Services;

use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyInventoryLevel;
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

        do {
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
        variants(first: 100) {
          edges {
            node {
              id
              title
              sku
              barcode
              price
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

            $page = is_array($conn['pageInfo'] ?? null) ? $conn['pageInfo'] : [];
            $hasNext = (bool) ($page['hasNextPage'] ?? false);
            $cursor = $hasNext ? ($page['endCursor'] ?? null) : null;
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
            $variant->barcode = (string) ($node['barcode'] ?? $variant->barcode);
            $variant->price = isset($node['price']) ? (float) $node['price'] : $variant->price;

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
        // crm_locked_at is set only when CRM pushes edits (see pushVariantToShopify).
        $variant->save();

        return true;
    }

    /**
     * Push CRM-edited fields to Shopify.
     *
     * @param  array{title?:string|null, sku?:string|null, weight?:float|null, weight_unit?:string|null, product_title?:string|null}  $fields
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
        if (array_key_exists('weight', $fields) && $fields['weight'] !== null) {
            $unit = strtoupper((string) ($fields['weight_unit'] ?? $variant->weight_unit ?? 'POUNDS'));
            if (! in_array($unit, ['GRAMS', 'KILOGRAMS', 'OUNCES', 'POUNDS'], true)) {
                $unit = 'POUNDS';
            }
            $variantInput['inventoryItem'] = array_merge(
                $variantInput['inventoryItem'] ?? [],
                [
                    'measurement' => [
                        'weight' => [
                            'value' => (float) $fields['weight'],
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
            if (isset($fields['weight'])) {
                $variant->weight = (float) $fields['weight'];
            }
            if (isset($fields['weight_unit'])) {
                $variant->weight_unit = (string) $fields['weight_unit'];
            }
            $variant->crm_locked_at = now();
            $variant->save();
        }

        return $variant->fresh(['product']);
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
