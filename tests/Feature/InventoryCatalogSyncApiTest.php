<?php

namespace Tests\Feature;

use App\Jobs\SyncInventoryCatalogPageJob;
use App\Models\ClientAccount;
use App\Models\InventoryProductCrmStatus;
use App\Models\ShipHeroInventoryProductDetailCache;
use App\Models\ShipHeroInventoryProductIndex;
use App\Models\User;
use App\Services\ShipHeroClient;
use App\Services\ShipHeroInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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

    public function test_concurrent_catalog_refresh_returns_202_while_running(): void
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
            ->assertStatus(202)
            ->assertJsonPath('message', 'Catalog sync already in progress.')
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

    public function test_list_with_empty_index_returns_ok_without_shiphero(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([$this->inventoryViewPermission()->id]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'company_name' => 'Empty Catalog Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-empty-catalog-1',
            'inventory_catalog_sync_status' => 'idle',
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');
        $this->app->instance(ShipHeroClient::class, $client);

        $this->getJson('/api/inventory-beta/list?'.http_build_query([
            'client_account_id' => $account->id,
            'first' => 50,
            'kits' => 'all',
            'active_status' => 'active',
        ]))
            ->assertOk()
            ->assertJsonPath('rows', [])
            ->assertJsonPath('page_info.has_next_page', false);
    }

    public function test_catalog_sync_endpoint_returns_meta_without_list_query(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([$this->inventoryViewPermission()->id]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'company_name' => 'Catalog Sync Poll Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-poll-1',
            'inventory_catalog_sync_status' => 'running',
            'inventory_catalog_sync_started_at' => now(),
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');
        $this->app->instance(ShipHeroClient::class, $client);

        $this->getJson('/api/inventory-beta/catalog-sync?'.http_build_query([
            'client_account_id' => $account->id,
        ]))
            ->assertOk()
            ->assertJsonPath('catalog_sync.inventory_catalog_sync_status', 'running');
    }

    public function test_refresh_dispatches_background_job_and_returns_202(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->permissions()->sync([$this->inventoryViewPermission()->id]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'company_name' => 'Job Sync Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-job-sync-1',
            'inventory_catalog_sync_status' => 'idle',
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');
        $this->app->instance(ShipHeroClient::class, $client);

        $this->getJson('/api/inventory-beta/list?'.http_build_query([
            'client_account_id' => $account->id,
            'refresh' => 1,
            'sync_mode' => 'incremental',
        ]))
            ->assertStatus(202)
            ->assertJsonPath('catalog_sync.inventory_catalog_sync_status', 'running');

        Queue::assertPushed(SyncInventoryCatalogPageJob::class, function ($job) use ($account) {
            return $job->clientAccountId === $account->id
                && $job->customerAccountId === 'sh-job-sync-1';
        });
    }

    public function test_refresh_uses_async_queue_connection_when_default_is_sync(): void
    {
        config(['queue.default' => 'sync']);
        Queue::fake();

        $user = User::factory()->create();
        $user->permissions()->sync([$this->inventoryViewPermission()->id]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'company_name' => 'Async Queue Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-async-queue-1',
            'inventory_catalog_sync_status' => 'idle',
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');
        $this->app->instance(ShipHeroClient::class, $client);

        $this->getJson('/api/inventory-beta/list?'.http_build_query([
            'client_account_id' => $account->id,
            'refresh' => 1,
        ]))->assertStatus(202);

        Queue::assertPushedOn('database-long', SyncInventoryCatalogPageJob::class);
    }

    public function test_list_read_hides_crm_inactive_skus_without_join(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([$this->inventoryViewPermission()->id]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'company_name' => 'CRM Filter Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-crm-filter-1',
        ]);

        foreach (['ACTIVE-SKU', 'INACTIVE-SKU'] as $sku) {
            ShipHeroInventoryProductIndex::query()->create([
                'client_account_id' => $account->id,
                'shiphero_customer_account_id' => 'sh-crm-filter-1',
                'sku' => $sku,
                'sku_search' => strtolower($sku),
                'name' => $sku,
                'name_search' => strtolower($sku),
                'product_active' => true,
                'kit' => false,
                'kit_build' => false,
                'warehouse_id' => 'WH1',
                'warehouse_active' => true,
                'on_hand' => $sku === 'ACTIVE-SKU' ? 10 : 5,
                'allocated' => 0,
                'backorder' => 0,
                'synced_at' => now(),
            ]);
        }

        InventoryProductCrmStatus::query()->create([
            'client_account_id' => $account->id,
            'sku' => 'INACTIVE-SKU',
            'crm_active' => false,
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');
        $this->app->instance(ShipHeroClient::class, $client);

        $this->getJson('/api/inventory-beta/list?'.http_build_query([
            'client_account_id' => $account->id,
            'active_status' => 'active',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.sku', 'ACTIVE-SKU')
            ->assertJsonPath('rows.0.crm_active', true);
    }

    public function test_list_read_works_when_crm_status_table_is_missing(): void
    {
        Schema::dropIfExists('inventory_product_crm_status');

        $user = User::factory()->create();
        $user->permissions()->sync([$this->inventoryViewPermission()->id]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'company_name' => 'No CRM Table Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-no-crm-1',
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => 'sh-no-crm-1',
            'sku' => 'SKU-NO-CRM',
            'sku_search' => 'sku-no-crm',
            'name' => 'No CRM',
            'name_search' => 'no crm',
            'product_active' => true,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH1',
            'warehouse_active' => true,
            'on_hand' => 3,
            'allocated' => 0,
            'backorder' => 0,
            'synced_at' => now(),
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');
        $this->app->instance(ShipHeroClient::class, $client);

        $this->getJson('/api/inventory-beta/list?'.http_build_query([
            'client_account_id' => $account->id,
        ]))
            ->assertOk()
            ->assertJsonPath('rows.0.sku', 'SKU-NO-CRM');
    }
}
