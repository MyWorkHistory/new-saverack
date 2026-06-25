<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ShipHeroInventoryProductDetailCache;
use App\Models\ShipHeroInventoryProductIndex;
use App\Models\User;
use App\Services\ShipHeroClient;
use App\Services\ShipHeroInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class InventoryCatalogSyncApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function inventoryViewPermission()
    {
        return \App\Models\Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['name' => 'View Inventory']
        );
    }

    public function test_sync_catalog_product_endpoint_upserts_index_and_clears_detail_cache(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([$this->inventoryViewPermission()->id]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'company_name' => 'SKU Sync Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-sku-sync-1',
        ]);

        ShipHeroInventoryProductDetailCache::query()->create([
            'client_account_id' => $account->id,
            'sku' => 'ABC-1',
            'sku_search' => 'abc-1',
            'product_json' => ['sku' => 'ABC-1'],
            'product_synced_at' => now(),
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldReceive('query')
            ->andReturn([
                'data' => [
                    'product' => [
                        'request_id' => 'x',
                        'data' => [
                            'id' => 'prod-abc',
                            'sku' => 'ABC-1',
                            'name' => 'Updated Name',
                            'barcode' => '123',
                            'active' => true,
                            'kit' => false,
                            'kit_build' => false,
                            'images' => [],
                            'warehouse_products' => [
                                [
                                    'warehouse_id' => 'WH1',
                                    'on_hand' => 12,
                                    'allocated' => 1,
                                    'available' => 11,
                                    'backorder' => 0,
                                    'active' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $this->app->instance(ShipHeroClient::class, $client);

        $this->postJson('/api/inventory-beta/products/ABC-1/sync', [
            'client_account_id' => $account->id,
        ])->assertOk()
            ->assertJsonPath('rows.0.sku', 'ABC-1');

        $this->assertDatabaseHas('shiphero_inventory_product_index', [
            'client_account_id' => $account->id,
            'sku' => 'ABC-1',
            'name' => 'Updated Name',
        ]);
        $this->assertDatabaseMissing('shiphero_inventory_product_detail_cache', [
            'client_account_id' => $account->id,
            'sku_search' => 'abc-1',
        ]);
    }

    public function test_concurrent_catalog_refresh_returns_409_while_running(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([$this->inventoryViewPermission()->id]);
        Sanctum::actingAs($user);

        $syncedAt = now()->subHour();
        $account = ClientAccount::query()->create([
            'company_name' => 'Sync Lock Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-lock-1',
            'inventory_catalog_sync_status' => 'running',
            'inventory_catalog_sync_started_at' => now(),
            'inventory_catalog_synced_at' => $syncedAt,
        ]);

        $this->getJson('/api/inventory-beta/list?'.http_build_query([
            'client_account_id' => $account->id,
            'refresh' => 1,
        ]))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Catalog sync is already in progress.')
            ->assertJsonPath('catalog_sync.inventory_catalog_sync_status', 'running');

        $this->assertDatabaseHas('client_accounts', [
            'id' => $account->id,
            'inventory_catalog_sync_status' => 'running',
        ]);
    }

    public function test_list_returns_catalog_sync_timestamp_for_account(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([$this->inventoryViewPermission()->id]);
        Sanctum::actingAs($user);

        $syncedAt = now()->subMinutes(10);
        $account = ClientAccount::query()->create([
            'company_name' => 'Catalog Meta Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-meta-1',
            'inventory_catalog_sync_status' => 'idle',
            'inventory_catalog_synced_at' => $syncedAt,
            'inventory_catalog_product_count' => 42,
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => 'sh-meta-1',
            'sku' => 'SKU-1',
            'sku_search' => 'sku-1',
            'name' => 'Product One',
            'name_search' => 'product one',
            'product_active' => true,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH1',
            'warehouse_active' => true,
            'on_hand' => 5,
            'allocated' => 0,
            'available' => 5,
            'backorder' => 0,
            'synced_at' => now(),
        ]);

        $response = $this->getJson('/api/inventory-beta/list?'.http_build_query([
            'client_account_id' => $account->id,
        ]))->assertOk();

        $response->assertJsonPath('catalog_sync.inventory_catalog_sync_status', 'idle');
        $response->assertJsonPath('catalog_sync.inventory_catalog_product_count', 42);
        $this->assertNotNull($response->json('catalog_sync.inventory_catalog_synced_at'));
    }
}
