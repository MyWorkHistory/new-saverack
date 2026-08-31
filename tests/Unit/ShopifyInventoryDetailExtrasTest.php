<?php

namespace Tests\Unit;

use App\Jobs\GenerateShopifyVariantBarcodeLabelJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyProductSyncService;
use App\Services\ShopifyVariantBarcodeLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShopifyInventoryDetailExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): ClientAccountShopifyConnection
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Shopify Detail Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        return ClientAccountShopifyConnection::query()->create([
            'client_account_id' => $account->id,
            'shop_domain' => 'detail.myshopify.com',
            'admin_api_access_token' => 'shpat_test',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);
    }

    public function test_synced_image_url_is_set_once_and_not_overwritten(): void
    {
        Storage::fake('public');
        $connection = $this->connection();
        $service = app(ShopifyProductSyncService::class);

        $service->upsertProductFromShopifyNode($connection, [
            'id' => 'gid://shopify/Product/100',
            'title' => 'Camera',
            'handle' => 'camera',
            'status' => 'ACTIVE',
            'featuredImage' => ['url' => 'https://cdn.example/first.jpg'],
            'variants' => [
                'edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/ProductVariant/200',
                        'title' => 'Default',
                        'sku' => 'CAM-1',
                        'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/300'],
                        'image' => ['url' => 'https://cdn.example/first.jpg'],
                    ],
                ]],
            ],
        ], true);

        $variant = ShopifyProductVariant::query()->where('shopify_variant_id', '200')->first();
        $this->assertNotNull($variant);
        $this->assertSame('https://cdn.example/first.jpg', $variant->synced_image_url);

        $service->upsertProductFromShopifyNode($connection, [
            'id' => 'gid://shopify/Product/100',
            'title' => 'Camera',
            'handle' => 'camera',
            'status' => 'ACTIVE',
            'featuredImage' => ['url' => 'https://cdn.example/second.jpg'],
            'variants' => [
                'edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/ProductVariant/200',
                        'title' => 'Default',
                        'sku' => 'CAM-1',
                        'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/300'],
                        'image' => ['url' => 'https://cdn.example/second.jpg'],
                    ],
                ]],
            ],
        ], false);

        $variant->refresh();
        $this->assertSame('https://cdn.example/first.jpg', $variant->synced_image_url);
    }

    public function test_barcode_label_uses_sku_for_display_and_barcode_for_qr(): void
    {
        Storage::fake('public');
        $connection = $this->connection();
        $product = ShopifyProduct::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => '101',
            'title' => 'Widget',
            'status' => 'active',
            'crm_product_kind' => ShopifyProduct::KIND_STANDARD,
        ]);
        $variant = ShopifyProductVariant::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => $product->id,
            'shopify_variant_id' => '201',
            'shopify_inventory_item_id' => '301',
            'title' => 'Default',
            'sku' => 'SKU-ONLY',
            'barcode' => null,
        ]);

        $labels = app(ShopifyVariantBarcodeLabelService::class);
        $this->assertSame('SKU-ONLY', $labels->displayCodeForVariant($variant));
        $this->assertSame('SKU-ONLY', $labels->qrPayloadForVariant($variant));

        $path = $labels->ensureLabel($variant, true);
        $this->assertNotNull($path);
        $variant->refresh();
        $this->assertSame($labels->labelFingerprint($variant), $variant->barcode_label_payload);
        Storage::disk('public')->assertExists($path);
        $svg = Storage::disk('public')->get($path);
        $this->assertStringContainsString('width="4in"', $svg);
        $this->assertStringContainsString('height="1.5in"', $svg);
        $this->assertStringContainsString('>SKU-ONLY<', $svg);

        $variant->barcode = 'BC-999';
        $variant->save();
        (new GenerateShopifyVariantBarcodeLabelJob((int) $variant->id))
            ->handle($labels);

        $variant->refresh();
        $this->assertSame($labels->labelFingerprint($variant), $variant->barcode_label_payload);
        $this->assertTrue(Storage::disk('public')->exists($variant->barcode_label_path));
        $svg = Storage::disk('public')->get($variant->barcode_label_path);
        $this->assertStringContainsString('>SKU-ONLY<', $svg);
    }

    public function test_barcode_label_regenerates_when_sku_changes_with_existing_barcode(): void
    {
        Storage::fake('public');
        $connection = $this->connection();
        $product = ShopifyProduct::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => '102',
            'title' => 'Speaker',
            'status' => 'active',
            'crm_product_kind' => ShopifyProduct::KIND_STANDARD,
        ]);
        $variant = ShopifyProductVariant::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => $product->id,
            'shopify_variant_id' => '202',
            'shopify_inventory_item_id' => '302',
            'title' => 'Default',
            'sku' => 'OLD-SKU',
            'barcode' => '012345678905',
        ]);

        $labels = app(ShopifyVariantBarcodeLabelService::class);
        $labels->ensureLabel($variant, true);
        $variant->refresh();
        $oldFingerprint = $variant->barcode_label_payload;
        $oldSvg = Storage::disk('public')->get($variant->barcode_label_path);
        $this->assertStringContainsString('>OLD-SKU<', $oldSvg);

        $variant->sku = 'test';
        $variant->save();
        (new GenerateShopifyVariantBarcodeLabelJob((int) $variant->id))
            ->handle($labels);

        $variant->refresh();
        $this->assertNotSame($oldFingerprint, $variant->barcode_label_payload);
        $svg = Storage::disk('public')->get($variant->barcode_label_path);
        $this->assertStringContainsString('>test<', $svg);
        $this->assertStringContainsString('>Speaker<', $svg);
    }
}
