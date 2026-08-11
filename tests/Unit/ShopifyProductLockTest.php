<?php

namespace Tests\Unit;

use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyProduct;
use App\Services\ShopifyProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyProductLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_locked_product_does_not_overwrite_title_on_update(): void
    {
        $connection = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => \App\Models\ClientAccount::query()->create([
                'company_name' => 'Shopify Test Co',
                'status' => \App\Models\ClientAccount::STATUS_ACTIVE,
            ])->id,
            'shop_domain' => 'test.myshopify.com',
            'admin_api_access_token' => 'shpat_test',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);

        $service = app(ShopifyProductSyncService::class);
        $service->upsertProductFromShopifyNode($connection, [
            'id' => 'gid://shopify/Product/10',
            'title' => 'Original Title',
            'handle' => 'original',
            'status' => 'ACTIVE',
            'variants' => [
                'edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/ProductVariant/20',
                        'title' => 'Default',
                        'sku' => 'SKU-1',
                        'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/30'],
                    ],
                ]],
            ],
        ], true);

        $product = ShopifyProduct::query()->where('shopify_product_id', '10')->first();
        $this->assertNotNull($product);
        $this->assertSame('Original Title', $product->title);
        $this->assertNotNull($product->crm_locked_at);

        $service->upsertProductFromShopifyNode($connection, [
            'id' => 'gid://shopify/Product/10',
            'title' => 'Changed In Shopify',
            'handle' => 'changed',
            'status' => 'ACTIVE',
            'variants' => [
                'edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/ProductVariant/20',
                        'title' => 'Default',
                        'sku' => 'SKU-CHANGED',
                        'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/30'],
                    ],
                ]],
            ],
        ], false);

        $product->refresh();
        $this->assertSame('Original Title', $product->title);

        $variant = $connection->variants()->where('shopify_variant_id', '20')->first();
        $this->assertSame('SKU-1', $variant->sku);
    }
}
