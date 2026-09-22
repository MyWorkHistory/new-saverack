<?php

namespace App\Services;

use App\Jobs\PushShopifyVariantJob;
use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyConnectionShippingPackage;
use App\Models\ShopifyPackagingItem;
use App\Models\ShopifyProductVariant;
use App\Support\ShopifyGid;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * CRM owns Shopify product weight + shipping package assignment.
 * Packaging catalog dims/weight drive custom shipping packages (inches / lb).
 */
class ShopifyShippingPackageSyncService
{
    /** @var ShopifyClient */
    private $client;

    public function __construct(ShopifyClient $client)
    {
        $this->client = $client;
    }

    /**
     * Ensure the shop has a shipping package matching this CRM packaging item, then return its GID/id.
     */
    public function ensurePackageForConnection(
        ClientAccountShopifyConnection $connection,
        ShopifyPackagingItem $packaging
    ): ?string {
        if (! $connection->hasCredentials()) {
            return null;
        }
        if ($packaging->category !== ShopifyPackagingItem::CATEGORY_PACKAGING) {
            return null;
        }

        $map = ShopifyConnectionShippingPackage::query()->firstOrNew([
            'connection_id' => $connection->id,
            'shopify_packaging_item_id' => $packaging->id,
        ]);

        $input = $this->packageInput($packaging);
        $api = $this->client->forConnection($connection);

        if ($map->exists && trim((string) $map->shopify_shipping_package_id) !== '') {
            $packageId = $this->normalizePackageId((string) $map->shopify_shipping_package_id);
            try {
                $this->updatePackage($api, $packageId, $input);
                $map->shopify_shipping_package_id = $this->storePackageId($packageId);
                $map->save();

                return $this->normalizePackageId((string) $map->shopify_shipping_package_id);
            } catch (Throwable $e) {
                Log::warning('shopify.shipping_package.update_failed', [
                    'connection_id' => $connection->id,
                    'packaging_item_id' => $packaging->id,
                    'package_id' => $packageId,
                    'message' => $e->getMessage(),
                ]);
                // Fall through and try create if update failed (stale id).
            }
        }

        try {
            $createdId = $this->createPackage($api, $input);
            if ($createdId === null || $createdId === '') {
                return null;
            }
            $map->shopify_shipping_package_id = $this->storePackageId($createdId);
            $map->save();

            return $this->normalizePackageId((string) $map->shopify_shipping_package_id);
        } catch (Throwable $e) {
            Log::warning('shopify.shipping_package.create_failed', [
                'connection_id' => $connection->id,
                'packaging_item_id' => $packaging->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Push CRM product weight + assigned packaging package to Shopify for one variant.
     */
    public function pushVariantShipping(ShopifyProductVariant $variant): void
    {
        $variant->loadMissing(['connection', 'packagingItem', 'packagingAssignments', 'product']);
        $connection = $variant->connection;
        if ($connection === null || ! $connection->hasCredentials()) {
            return;
        }

        $packaging = $this->primaryPackaging($variant);
        $shippingPackageGid = null;
        if ($packaging !== null) {
            $shippingPackageGid = $this->ensurePackageForConnection($connection, $packaging);
        }

        $fields = [
            'weight' => $variant->weight,
            'weight_unit' => $variant->weight_unit ?: 'POUNDS',
        ];
        if ($shippingPackageGid !== null) {
            $fields['shipping_package_id'] = $shippingPackageGid;
        }

        app(ShopifyProductSyncService::class)->pushVariantToShopify($variant, $fields);
    }

    /**
     * Queue CRM → Shopify shipping push for a variant (weight + package).
     */
    public function dispatchVariantShippingPush(ShopifyProductVariant $variant): void
    {
        $variant->loadMissing(['packagingItem', 'packagingAssignments']);
        $packaging = $this->primaryPackaging($variant);
        $fields = [
            'weight' => $variant->weight,
            'weight_unit' => $variant->weight_unit ?: 'POUNDS',
            'sync_shipping_package' => true,
            'packaging_item_id' => $packaging !== null ? (int) $packaging->id : null,
        ];
        PushShopifyVariantJob::dispatch((int) $variant->id, $fields);
    }

    /**
     * After a packaging catalog item changes, refresh mapped Shopify packages and re-push assigned SKUs.
     */
    public function syncPackagingItemToShopify(ShopifyPackagingItem $packaging): void
    {
        if ($packaging->category !== ShopifyPackagingItem::CATEGORY_PACKAGING) {
            return;
        }

        $maps = ShopifyConnectionShippingPackage::query()
            ->where('shopify_packaging_item_id', $packaging->id)
            ->with('connection')
            ->get();

        foreach ($maps as $map) {
            $connection = $map->connection;
            if ($connection === null || ! $connection->hasCredentials()) {
                continue;
            }
            $this->ensurePackageForConnection($connection, $packaging);
        }

        $variantIds = ShopifyProductVariant::query()
            ->where('packaging_item_id', $packaging->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Also variants linked via M2M when packaging_item_id is stale.
        $viaPivot = ShopifyProductVariant::query()
            ->whereHas('packagingAssignments', function ($q) use ($packaging) {
                $q->where('shopify_packaging_items.id', $packaging->id)
                    ->where('shopify_packaging_items.category', ShopifyPackagingItem::CATEGORY_PACKAGING);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach (array_values(array_unique(array_merge($variantIds, $viaPivot))) as $variantId) {
            $variant = ShopifyProductVariant::query()->find($variantId);
            if ($variant !== null) {
                $this->dispatchVariantShippingPush($variant);
            }
        }
    }

    public function primaryPackaging(ShopifyProductVariant $variant): ?ShopifyPackagingItem
    {
        $variant->loadMissing(['packagingItem', 'packagingAssignments']);
        $item = $variant->packagingItem;
        if ($item !== null && $item->category === ShopifyPackagingItem::CATEGORY_PACKAGING) {
            return $item;
        }
        foreach ($variant->packagingAssignments as $assigned) {
            if ($assigned->category === ShopifyPackagingItem::CATEGORY_PACKAGING) {
                return $assigned;
            }
        }

        return null;
    }

    /**
     * @return array{name: string, type: string, default: bool, dimensions: array{length: float, width: float, height: float, unit: string}, weight: array{value: float, unit: string}}
     */
    private function packageInput(ShopifyPackagingItem $packaging): array
    {
        $name = trim((string) $packaging->name);
        if ($name === '') {
            $name = trim($packaging->typeLabel()) ?: 'CRM Package';
        }

        return [
            'name' => $name,
            'type' => $this->shopifyPackageType($packaging),
            'default' => false,
            'dimensions' => [
                'length' => (float) ($packaging->length ?? 0),
                'width' => (float) ($packaging->width ?? 0),
                'height' => (float) ($packaging->height ?? 0),
                'unit' => 'INCHES',
            ],
            'weight' => [
                'value' => (float) ($packaging->weight ?? 0),
                'unit' => 'POUNDS',
            ],
        ];
    }

    private function shopifyPackageType(ShopifyPackagingItem $packaging): string
    {
        $type = strtolower(trim((string) $packaging->type));
        if (in_array($type, ['poly_mailer', 'bubble_mailer', 'kraft_mailer'], true)) {
            return 'SOFT_PACKAGE';
        }

        return 'BOX';
    }

    /**
     * @param  object  $api  Shopify API client for connection
     * @param  array<string, mixed>  $input
     */
    private function createPackage($api, array $input): ?string
    {
        // Prefer DeliveryPackage resource naming used by newer Admin GraphQL.
        $mutations = [
            <<<'GQL'
mutation shippingPackageCreate($shippingPackage: CustomShippingPackageInput!) {
  shippingPackageCreate(shippingPackage: $shippingPackage) {
    shippingPackage { id }
    userErrors { field message }
  }
}
GQL,
        ];

        foreach ($mutations as $gql) {
            try {
                $data = $api->graphql($gql, ['shippingPackage' => $input]);
                if (isset($data['errors']) && is_array($data['errors']) && $data['errors'] !== []) {
                    $msg = (string) ($data['errors'][0]['message'] ?? 'GraphQL error');
                    if ($this->isUnknownFieldError($msg)) {
                        continue;
                    }
                    throw new RuntimeException($msg);
                }
                $payload = $data['shippingPackageCreate'] ?? null;
                $this->assertNoUserErrors($payload);
                $id = (string) ($payload['shippingPackage']['id'] ?? '');

                return $id !== '' ? $id : null;
            } catch (Throwable $e) {
                if ($this->isUnknownFieldError($e->getMessage())) {
                    continue;
                }
                throw $e;
            }
        }

        throw new RuntimeException(
            'Shopify shippingPackageCreate is not available on this shop/API version. '
            .'Create the package once in Shopify Admin (Settings → Shipping and delivery → Packages), '
            .'or upgrade API scopes (write_shipping), then retry.'
        );
    }

    /**
     * @param  object  $api
     * @param  array<string, mixed>  $input
     */
    private function updatePackage($api, string $packageId, array $input): void
    {
        $gid = str_starts_with($packageId, 'gid://')
            ? $packageId
            : ShopifyGid::of('DeliveryPackage', $packageId);

        $data = $api->graphql(
            <<<'GQL'
mutation shippingPackageUpdate($id: ID!, $shippingPackage: CustomShippingPackageInput!) {
  shippingPackageUpdate(id: $id, shippingPackage: $shippingPackage) {
    userErrors { field message }
  }
}
GQL
            ,
            [
                'id' => $gid,
                'shippingPackage' => $input,
            ]
        );
        $this->assertNoUserErrors($data['shippingPackageUpdate'] ?? null);
    }

    private function normalizePackageId(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, 'gid://')) {
            return $raw;
        }

        // Prefer DeliveryPackage; ShippingPackage is also seen in older docs.
        return ShopifyGid::of('DeliveryPackage', $raw);
    }

    private function storePackageId(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, 'gid://')) {
            return $raw;
        }
        $numeric = ShopifyGid::toId($raw);

        return $numeric !== '' ? $numeric : $raw;
    }

    private function isUnknownFieldError(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'doesn\'t exist')
            || str_contains($m, 'does not exist')
            || str_contains($m, 'undefined field')
            || str_contains($m, 'unknown field')
            || str_contains($m, 'access denied')
            || str_contains($m, 'access_denied');
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function assertNoUserErrors(?array $payload): void
    {
        $errors = $payload['userErrors'] ?? [];
        if (! is_array($errors) || $errors === []) {
            return;
        }
        $messages = [];
        foreach ($errors as $err) {
            if (is_array($err) && isset($err['message'])) {
                $messages[] = (string) $err['message'];
            }
        }
        throw new RuntimeException($messages !== [] ? implode('; ', $messages) : 'Shopify shipping package error.');
    }
}
