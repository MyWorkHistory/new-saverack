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

    public function test_refresh_clears_inventory_index_before_fetching_live_rows(): void
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
        $payload = $service->listInventoryRows(
            'sh-refresh-1',
            50,
            null,
            'all',
            'active',
            null,
            0,
            $account->id,
            false,
            true
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
}
