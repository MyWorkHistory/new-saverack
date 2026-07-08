<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\ShipHeroInventoryProductIndex;
use App\Services\ShipHeroClient;
use App\Services\ShipHeroInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ShipHeroInventoryRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_full_sync_clears_inventory_index_before_fetching_live_rows(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Refresh Index Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-refresh-1',
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => 'sh-refresh-1',
            'sku' => 'OLD-SKU',
            'sku_search' => 'old-sku',
            'name' => 'Old product',
            'name_search' => 'old product',
            'product_active' => true,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH1',
            'warehouse_active' => true,
            'on_hand' => 99,
            'allocated' => 0,
            'backorder' => 0,
            'synced_at' => now(),
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldReceive('query')
            ->once()
            ->andReturn([
                'data' => [
                    'products' => [
                        'data' => [
                            'pageInfo' => [
                                'hasNextPage' => false,
                                'endCursor' => null,
                            ],
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'prod-new',
                                        'sku' => 'NEW-SKU',
                                        'name' => 'New product',
                                        'barcode' => null,
                                        'active' => true,
                                        'kit' => false,
                                        'kit_build' => false,
                                        'images' => [],
                                        'warehouse_products' => [
                                            [
                                                'warehouse_id' => 'WH1',
                                                'on_hand' => 6,
                                                'allocated' => 0,
                                                'available' => 6,
                                                'backorder' => 0,
                                                'active' => true,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $service = new ShipHeroInventoryService($client);
        $account->inventory_catalog_sync_status = 'running';
        $account->inventory_catalog_sync_started_at = now();
        $account->save();

        $payload = $service->syncCatalogInventoryPage(
            $account->id,
            'sh-refresh-1',
            50,
            null,
            ShipHeroInventoryService::CATALOG_SYNC_FULL
        );

        $this->assertSame('NEW-SKU', $payload['rows'][0]['sku'] ?? null);
        $this->assertDatabaseMissing('shiphero_inventory_product_index', [
            'client_account_id' => $account->id,
            'sku' => 'OLD-SKU',
        ]);
        $this->assertDatabaseHas('shiphero_inventory_product_index', [
            'client_account_id' => $account->id,
            'sku' => 'NEW-SKU',
            'on_hand' => 6,
        ]);
    }

    public function test_incremental_sync_preserves_existing_rows_until_finalize(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Incremental Sync Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-inc-1',
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => 'sh-inc-1',
            'sku' => 'OLD-SKU',
            'sku_search' => 'old-sku',
            'name' => 'Old product',
            'name_search' => 'old product',
            'product_active' => true,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH1',
            'warehouse_active' => true,
            'on_hand' => 99,
            'allocated' => 0,
            'backorder' => 0,
            'synced_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldReceive('query')
            ->once()
            ->andReturn([
                'data' => [
                    'products' => [
                        'data' => [
                            'pageInfo' => [
                                'hasNextPage' => false,
                                'endCursor' => null,
                            ],
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'prod-new',
                                        'sku' => 'NEW-SKU',
                                        'name' => 'New product',
                                        'barcode' => null,
                                        'active' => true,
                                        'kit' => false,
                                        'kit_build' => false,
                                        'images' => [],
                                        'warehouse_products' => [
                                            [
                                                'warehouse_id' => 'WH1',
                                                'on_hand' => 6,
                                                'allocated' => 0,
                                                'available' => 6,
                                                'backorder' => 0,
                                                'active' => true,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $service = new ShipHeroInventoryService($client);
        $account->inventory_catalog_sync_status = 'running';
        $account->inventory_catalog_sync_started_at = now();
        $account->save();

        $service->syncCatalogInventoryPage(
            $account->id,
            'sh-inc-1',
            50,
            null,
            ShipHeroInventoryService::CATALOG_SYNC_INCREMENTAL
        );

        $this->assertDatabaseHas('shiphero_inventory_product_index', [
            'client_account_id' => $account->id,
            'sku' => 'OLD-SKU',
            'product_active' => true,
        ]);
        $this->assertDatabaseHas('shiphero_inventory_product_index', [
            'client_account_id' => $account->id,
            'sku' => 'NEW-SKU',
        ]);

        $nextId = null;
        do {
            $nextId = $service->finalizeIncrementalCatalogSyncBatch($account->id, $nextId);
        } while ($nextId !== null);

        $this->assertDatabaseHas('shiphero_inventory_product_index', [
            'client_account_id' => $account->id,
            'sku' => 'OLD-SKU',
            'product_active' => false,
        ]);
    }

    public function test_backorder_only_list_reads_from_index_with_quantities(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'OOS Index Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-oos-idx-1',
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => 'sh-oos-idx-1',
            'shiphero_product_id' => 'prod-oos-1',
            'sku' => 'OOS-SKU',
            'sku_search' => 'oos-sku',
            'name' => 'OOS product',
            'name_search' => 'oos product',
            'product_active' => true,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH1',
            'warehouse_active' => true,
            'on_hand' => 2,
            'allocated' => 5,
            'backorder' => 3,
            'synced_at' => now(),
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');

        $service = new ShipHeroInventoryService($client);
        $payload = $service->listInventoryRows(
            'sh-oos-idx-1',
            50,
            null,
            'all',
            'active',
            null,
            0,
            $account->id,
            true,
            false
        );

        $this->assertCount(1, $payload['rows']);
        $row = $payload['rows'][0];
        $this->assertSame('OOS-SKU', $row['sku'] ?? null);
        $this->assertSame(2.0, (float) ($row['on_hand'] ?? 0));
        $this->assertSame(5.0, (float) ($row['allocated'] ?? 0));
        $this->assertSame(3.0, (float) ($row['backorder'] ?? 0));
    }

    public function test_backorder_only_list_excludes_zero_oversold_even_when_allocated_exceeds_on_hand(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'OOS Filter Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-oos-filter-1',
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => 'sh-oos-filter-1',
            'shiphero_product_id' => 'prod-oos-zero',
            'sku' => 'ZERO-OOS',
            'sku_search' => 'zero-oos',
            'name' => 'Zero oversold',
            'name_search' => 'zero oversold',
            'product_active' => true,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH1',
            'warehouse_active' => true,
            'on_hand' => 2,
            'allocated' => 5,
            'backorder' => 0,
            'synced_at' => now(),
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => 'sh-oos-filter-1',
            'shiphero_product_id' => 'prod-oos-pos',
            'sku' => 'POS-OOS',
            'sku_search' => 'pos-oos',
            'name' => 'Positive oversold',
            'name_search' => 'positive oversold',
            'product_active' => true,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH1',
            'warehouse_active' => true,
            'on_hand' => 1,
            'allocated' => 4,
            'backorder' => 2,
            'synced_at' => now(),
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');

        $service = new ShipHeroInventoryService($client);
        $payload = $service->listInventoryRows(
            'sh-oos-filter-1',
            50,
            null,
            'all',
            'active',
            null,
            0,
            $account->id,
            true,
            false
        );

        $this->assertCount(1, $payload['rows']);
        $this->assertSame('POS-OOS', $payload['rows'][0]['sku'] ?? null);
    }

    public function test_backorder_only_with_synced_index_but_zero_oversold_does_not_hit_shiphero(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'OOS Empty Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-oos-empty-1',
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => 'sh-oos-empty-1',
            'shiphero_product_id' => 'prod-in-stock',
            'sku' => 'IN-STOCK',
            'sku_search' => 'in-stock',
            'name' => 'In stock only',
            'name_search' => 'in stock only',
            'product_active' => true,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH1',
            'warehouse_active' => true,
            'on_hand' => 10,
            'allocated' => 1,
            'backorder' => 0,
            'synced_at' => now(),
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');

        $service = new ShipHeroInventoryService($client);
        $payload = $service->listInventoryRows(
            'sh-oos-empty-1',
            100,
            null,
            'all',
            'active',
            null,
            0,
            $account->id,
            true,
            false
        );

        $this->assertSame([], $payload['rows']);
        $this->assertFalse((bool) ($payload['page_info']['has_next_page'] ?? true));
    }

    public function test_catalog_read_with_empty_index_does_not_hit_shiphero(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Empty Index Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-empty-idx-1',
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');

        $service = new ShipHeroInventoryService($client);
        $payload = $service->listCatalogInventoryRows(
            'sh-empty-idx-1',
            50,
            null,
            'all',
            'active',
            null,
            0,
            $account->id,
            false,
            false
        );

        $this->assertSame([], $payload['rows']);
        $this->assertFalse((bool) ($payload['page_info']['has_next_page'] ?? true));
    }

    public function test_catalog_read_with_synced_index_zero_filter_does_not_hit_shiphero(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Zero Filter Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-zero-filter-1',
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => 'sh-zero-filter-1',
            'shiphero_product_id' => 'prod-active-only',
            'sku' => 'ACTIVE-ONLY',
            'sku_search' => 'active-only',
            'name' => 'Active only',
            'name_search' => 'active only',
            'product_active' => true,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH1',
            'warehouse_active' => true,
            'on_hand' => 4,
            'allocated' => 0,
            'backorder' => 0,
            'synced_at' => now(),
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');

        $service = new ShipHeroInventoryService($client);
        $payload = $service->listCatalogInventoryRows(
            'sh-zero-filter-1',
            50,
            null,
            'all',
            'inactive',
            null,
            0,
            $account->id,
            false,
            false
        );

        $this->assertSame([], $payload['rows']);
        $this->assertFalse((bool) ($payload['page_info']['has_next_page'] ?? true));
    }

    public function test_backorder_only_with_empty_index_and_no_refresh_does_not_hit_shiphero(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'OOS No Live Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-oos-nolive-1',
        ]);

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');

        $service = new ShipHeroInventoryService($client);
        $payload = $service->listInventoryRows(
            'sh-oos-nolive-1',
            100,
            null,
            'all',
            'active',
            null,
            0,
            $account->id,
            true,
            false
        );

        $this->assertSame([], $payload['rows']);
        $this->assertFalse((bool) ($payload['page_info']['has_next_page'] ?? true));
    }

    public function test_index_list_read_paginates_many_rows_without_count_query(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Large Index Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-large-1',
        ]);

        for ($i = 1; $i <= 55; $i++) {
            ShipHeroInventoryProductIndex::query()->create([
                'client_account_id' => $account->id,
                'shiphero_customer_account_id' => 'sh-large-1',
                'sku' => 'SKU-'.$i,
                'sku_search' => 'sku-'.$i,
                'name' => 'Product '.$i,
                'name_search' => 'product '.$i,
                'product_active' => true,
                'kit' => false,
                'kit_build' => false,
                'warehouse_id' => 'WH1',
                'warehouse_active' => true,
                'on_hand' => $i,
                'allocated' => 0,
                'backorder' => 0,
                'synced_at' => now(),
            ]);
        }

        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldNotReceive('query');

        $service = new ShipHeroInventoryService($client);
        $payload = $service->listCatalogInventoryRows(
            'sh-large-1',
            50,
            null,
            'all',
            'active',
            null,
            0,
            $account->id,
            false,
            false
        );

        $this->assertCount(50, $payload['rows']);
        $this->assertTrue((bool) ($payload['page_info']['has_next_page'] ?? false));
        $this->assertSame('idx:50', $payload['page_info']['end_cursor'] ?? null);
    }

    public function test_resolve_stale_does_not_fail_sync_with_recent_progress(): void
    {
        config(['services.shiphero.catalog_sync_stall_minutes' => 15]);

        $account = ClientAccount::query()->create([
            'company_name' => 'Recent Progress Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-recent-progress-1',
            'inventory_catalog_sync_status' => 'running',
            'inventory_catalog_sync_started_at' => now()->subHours(2),
            'inventory_catalog_sync_last_progress_at' => now()->subMinutes(2),
            'inventory_catalog_sync_pages_completed' => 4,
        ]);

        $service = new ShipHeroInventoryService(Mockery::mock(ShipHeroClient::class));
        $service->resolveStaleRunningCatalogSync($account->id);

        $account->refresh();
        $this->assertSame('running', $account->inventory_catalog_sync_status);
    }

    public function test_resolve_stale_fails_sync_when_last_progress_is_stale(): void
    {
        config(['services.shiphero.catalog_sync_stall_minutes' => 15]);

        $account = ClientAccount::query()->create([
            'company_name' => 'Stale Progress Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-stale-progress-1',
            'inventory_catalog_sync_status' => 'running',
            'inventory_catalog_sync_started_at' => now()->subHour(),
            'inventory_catalog_sync_last_progress_at' => now()->subMinutes(20),
            'inventory_catalog_sync_pages_completed' => 1,
        ]);

        $service = new ShipHeroInventoryService(Mockery::mock(ShipHeroClient::class));
        $service->resolveStaleRunningCatalogSync($account->id);

        $account->refresh();
        $this->assertSame('failed', $account->inventory_catalog_sync_status);
        $this->assertNotNull($account->inventory_catalog_sync_last_error);
        $this->assertStringContainsString('no progress', strtolower((string) $account->inventory_catalog_sync_last_error));
    }

    public function test_record_catalog_sync_page_progress_increments_pages_and_clears_error(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Page Progress Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-page-progress-1',
            'inventory_catalog_sync_status' => 'running',
            'inventory_catalog_sync_pages_completed' => 2,
            'inventory_catalog_sync_last_error' => 'Previous failure',
        ]);

        $service = new ShipHeroInventoryService(Mockery::mock(ShipHeroClient::class));
        $service->recordCatalogSyncPageProgress($account->id);

        $account->refresh();
        $this->assertSame(3, (int) $account->inventory_catalog_sync_pages_completed);
        $this->assertNull($account->inventory_catalog_sync_last_error);
        $this->assertNotNull($account->inventory_catalog_sync_last_progress_at);
    }

    public function test_catalog_sync_meta_includes_progress_and_error_fields(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Meta Progress Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-meta-progress-1',
            'inventory_catalog_sync_status' => 'failed',
            'inventory_catalog_sync_pages_completed' => 5,
            'inventory_catalog_sync_last_progress_at' => now()->subMinutes(3),
            'inventory_catalog_sync_last_error' => 'ShipHero timeout',
        ]);

        $service = new ShipHeroInventoryService(Mockery::mock(ShipHeroClient::class));
        $meta = $service->catalogSyncMetaForAccount($account->id);

        $this->assertSame('failed', $meta['inventory_catalog_sync_status']);
        $this->assertSame(5, $meta['inventory_catalog_sync_pages_completed']);
        $this->assertSame('ShipHero timeout', $meta['inventory_catalog_sync_last_error']);
        $this->assertNotNull($meta['inventory_catalog_sync_last_progress_at']);
    }
}
