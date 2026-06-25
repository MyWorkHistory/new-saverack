<?php

namespace Tests\Unit;

use App\Services\ShipHeroClient;
use App\Services\ShipHeroInventoryService;
use Mockery;
use Tests\TestCase;

class ResolveWarehouseLocationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_resolve_warehouse_location_uses_name_query_before_customer_scoped_catalog(): void
    {
        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldReceive('query')
            ->once()
            ->withArgs(function (string $graphql, array $vars) {
                return strpos($graphql, 'ShipHeroLocationByWarehouseName') !== false
                    && ($vars['warehouse_id'] ?? '') === 'WH1'
                    && ($vars['name'] ?? '') === 'A-01';
            })
            ->andReturn([
                'data' => [
                    'locations' => [
                        'data' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'loc-a01',
                                        'name' => 'A-01',
                                        'pickable' => true,
                                        'sellable' => true,
                                        'type' => ['name' => 'Bin (Small)'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $service = new ShipHeroInventoryService($client);
        $resolved = $service->resolveWarehouseLocation('WH1', 'A-01', 'customer-99');

        $this->assertNotNull($resolved);
        $this->assertSame('loc-a01', $resolved['id']);
        $this->assertSame('A-01', $resolved['name']);
    }

    public function test_resolve_warehouse_location_falls_back_to_full_warehouse_catalog(): void
    {
        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldReceive('query')
            ->andReturnUsing(function (string $graphql) {
                if (strpos($graphql, 'ShipHeroLocationByWarehouseName') !== false) {
                    return ['data' => ['locations' => ['data' => ['edges' => []]]]];
                }
                if (strpos($graphql, 'ShipHeroLocationsByWarehouseNoCustomer') !== false) {
                    return [
                        'data' => [
                            'locations' => [
                                'data' => [
                                    'edges' => [
                                        [
                                            'node' => [
                                                'id' => 'loc-backstock',
                                                'name' => 'BACK-01',
                                                'pickable' => false,
                                                'sellable' => true,
                                                'type' => ['name' => 'Overstock'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                return ['data' => ['locations' => ['data' => ['edges' => []]]]];
            });

        $service = new ShipHeroInventoryService($client);
        $resolved = $service->resolveWarehouseLocation('WH1', 'BACK-01', 'customer-99');

        $this->assertNotNull($resolved);
        $this->assertSame('loc-backstock', $resolved['id']);
    }
}
