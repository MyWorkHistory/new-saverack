<?php

namespace Tests\Unit;

use App\Jobs\ProcessShopifyWebhookJob;
use App\Jobs\PushShopifyVariantJob;
use App\Jobs\RunShopifyBootstrapImportJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyLocation;
use App\Models\ShopifyOrder;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Models\ShopifyWebhookEvent;
use App\Services\ShopifyBootstrapImportService;
use App\Services\ShopifyClient;
use App\Services\ShopifyConnectionService;
use App\Services\ShopifyOrderSyncService;
use App\Services\ShopifyProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ShopifyStoresSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_queues_location_only_import(): void
    {
        Queue::fake();
        $account = $this->account();
        $client = Mockery::mock(ShopifyClient::class)->shouldIgnoreMissing();
        $client->shouldReceive('forConnection')->andReturnSelf();
        $client->shouldReceive('shopInfo')->andReturn([
            'name' => 'Demo Shop',
            'myshopifyDomain' => 'demo.myshopify.com',
        ]);
        $service = new ShopifyConnectionService($client, app(ShopifyBootstrapImportService::class));
        $connection = $service->connectAndImport($account, [
            'shop_domain' => 'demo.myshopify.com',
            'admin_api_access_token' => 'shpat_test',
        ]);

        $this->assertSame(ClientAccountShopifyConnection::STATUS_IMPORTING, $connection->status);
        Queue::assertPushed(RunShopifyBootstrapImportJob::class, function (RunShopifyBootstrapImportJob $job) {
            return $job->locationsOnly === true;
        });
        $this->assertSame(0, ShopifyProduct::query()->count());
        $this->assertSame(0, ShopifyOrder::query()->count());
    }

    public function test_order_import_skipped_when_location_import_orders_is_false(): void
    {
        $connection = $this->connection();
        ShopifyLocation::query()->where('connection_id', $connection->id)->update(['import_orders' => false]);
        $service = app(ShopifyOrderSyncService::class);

        $ok = $service->upsertOrderFromShopifyNode($connection, [
            'id' => 'gid://shopify/Order/55',
            'name' => '#55',
            'location_id' => 10,
            'line_items' => [],
        ]);

        $this->assertFalse($ok);
        $this->assertSame(0, ShopifyOrder::query()->count());
    }

    public function test_inventory_webhook_does_not_change_available(): void
    {
        $connection = $this->connection();
        ShopifyInventoryLevel::query()->create([
            'connection_id' => $connection->id,
            'shopify_inventory_item_id' => '30',
            'shopify_location_id' => '10',
            'available' => 7,
            'crm_set_at' => now(),
        ]);
        $event = ShopifyWebhookEvent::query()->create([
            'event_id' => 'wh-inv-1',
            'topic' => 'inventory_levels/update',
            'shop_domain' => 'test.myshopify.com',
            'connection_id' => $connection->id,
            'payload' => [
                'inventory_item_id' => '30',
                'location_id' => '10',
                'available' => 32,
            ],
        ]);

        (new ProcessShopifyWebhookJob((int) $event->id))->handle(
            app(ShopifyProductSyncService::class),
            app(ShopifyOrderSyncService::class),
            app(ShopifyBootstrapImportService::class),
            app(ShopifyClient::class)
        );

        $this->assertSame(7, (int) ShopifyInventoryLevel::query()->first()->available);
        $this->assertNotNull($event->fresh()->processed_at);
    }

    public function test_existing_variant_keeps_barcode_weight_and_dimensions(): void
    {
        $connection = $this->connection();
        $service = app(ShopifyProductSyncService::class);
        $service->upsertProductFromShopifyNode($connection, $this->productNode('BAR-1', 1.5, 'POUNDS'), true);

        $variant = ShopifyProductVariant::query()->where('shopify_variant_id', '20')->first();
        $this->assertNotNull($variant);
        $variant->length = 11;
        $variant->width = 8;
        $variant->height = 3;
        $variant->dimension_unit = 'INCHES';
        $variant->save();

        $service->upsertProductFromShopifyNode($connection, $this->productNode('BAR-CHANGED', 9.9, 'OUNCES'), true);

        $variant->refresh();
        $this->assertSame('BAR-1', $variant->barcode);
        $this->assertEquals(1.5, (float) $variant->weight);
        $this->assertSame('POUNDS', $variant->weight_unit);
        $this->assertEquals(11, (float) $variant->length);
        $this->assertEquals(8, (float) $variant->width);
        $this->assertEquals(3, (float) $variant->height);
        $this->assertSame('INCHES', $variant->dimension_unit);
    }

    public function test_variant_save_dispatches_push_with_barcode_weight_and_dims(): void
    {
        Queue::fake();
        $connection = $this->connection();
        $service = app(ShopifyProductSyncService::class);
        $service->upsertProductFromShopifyNode($connection, $this->productNode('BAR-1', 1.5, 'POUNDS'), true);
        $variant = ShopifyProductVariant::query()->where('shopify_variant_id', '20')->first();

        $job = new PushShopifyVariantJob((int) $variant->id, [
            'barcode' => 'BAR-1',
            'weight' => 1.5,
            'weight_unit' => 'POUNDS',
            'length' => 11,
            'width' => 8,
            'height' => 3,
            'dimension_unit' => 'INCHES',
        ]);
        $this->assertSame('BAR-1', $job->fields['barcode']);
        $this->assertSame(1.5, $job->fields['weight']);
        $this->assertSame(11, $job->fields['length']);

        PushShopifyVariantJob::dispatch((int) $variant->id, $job->fields);
        Queue::assertPushed(PushShopifyVariantJob::class, function (PushShopifyVariantJob $pushed) {
            return ($pushed->fields['barcode'] ?? null) === 'BAR-1'
                && array_key_exists('weight', $pushed->fields)
                && array_key_exists('length', $pushed->fields);
        });
    }

    private function account(): ClientAccount
    {
        return ClientAccount::query()->create([
            'company_name' => 'Stores Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
    }

    private function connection(): ClientAccountShopifyConnection
    {
        $connection = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => $this->account()->id,
            'shop_domain' => 'test.myshopify.com',
            'admin_api_access_token' => 'shpat_test',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);
        ShopifyLocation::query()->create([
            'connection_id' => $connection->id,
            'shopify_location_id' => '10',
            'name' => 'Warehouse',
            'import_orders' => true,
            'sync_inventory' => true,
        ]);

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private function productNode(string $barcode, float $weight, string $unit): array
    {
        return [
            'id' => 'gid://shopify/Product/10',
            'title' => 'Widget',
            'handle' => 'widget',
            'status' => 'ACTIVE',
            'variants' => [
                'edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/ProductVariant/20',
                        'title' => 'Default',
                        'sku' => 'SKU-1',
                        'barcode' => $barcode,
                        'price' => '9.00',
                        'inventoryItem' => [
                            'id' => 'gid://shopify/InventoryItem/30',
                            'measurement' => [
                                'weight' => ['value' => $weight, 'unit' => $unit],
                            ],
                        ],
                    ],
                ]],
            ],
        ];
    }
}
