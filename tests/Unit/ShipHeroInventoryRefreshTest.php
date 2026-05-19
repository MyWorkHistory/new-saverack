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
}
