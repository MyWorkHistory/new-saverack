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
            ->andReturnUsing(function (string $graphql, array $vars) {
                if (strpos($graphql, 'ShipHeroLocationBySingularName') !== false) {
                    return ['data' => ['location' => ['data' => null]]];
                }
                if (strpos($graphql, 'ShipHeroLocationByWarehouseName') !== false) {
                    $this->assertSame('WH1', $vars['warehouse_id'] ?? '');
                    $this->assertSame('A-01', $vars['name'] ?? '');

                    return [
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
                    ];
                }
                if (strpos($graphql, 'ShipHeroLocationCatalogResolvePage') !== false) {
                    $this->fail('Should not paginate catalog when targeted name query succeeds.');
                }

                return ['data' => []];
            });

        $service = new ShipHeroInventoryService($client);
        $resolved = $service->resolveWarehouseLocation('WH1', 'A-01', 'customer-99');

        $this->assertNotNull($resolved);
        $this->assertSame('loc-a01', $resolved['id']);
        $this->assertSame('A-01', $resolved['name']);
    }

    public function test_resolve_warehouse_location_falls_back_to_paginated_catalog(): void
    {
        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldReceive('query')
            ->andReturnUsing(function (string $graphql) {
                if (strpos($graphql, 'ShipHeroLocationByWarehouseName') !== false) {
                    return ['data' => ['locations' => ['data' => ['edges' => []]]]];
                }
                if (strpos($graphql, 'ShipHeroItemLocationByName') !== false) {
                    return ['data' => ['item_locations' => ['data' => ['edges' => []]]]];
                }
                if (strpos($graphql, 'ShipHeroLocationBySingularName') !== false) {
                    return ['data' => ['location' => ['data' => null]]];
                }
                if (strpos($graphql, 'ShipHeroLocationCatalogResolvePage') !== false) {
                    return [
                        'data' => [
                            'locations' => [
                                'data' => [
                                    'pageInfo' => [
                                        'hasNextPage' => false,
                                        'endCursor' => null,
                                    ],
                                    'edges' => [
                                        [
                                            'node' => [
                                                'id' => 'loc-e12',
                                                'name' => 'E-12-025',
                                                'pickable' => true,
                                                'sellable' => true,
                                                'type' => ['name' => 'Bin (Small)'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
                if (strpos($graphql, 'ShipHeroLocationsByWarehouseNoCustomer') !== false) {
                    $this->fail('Should use paginated catalog resolve, not listLocations.');
                }

                return ['data' => []];
            });

        $service = new ShipHeroInventoryService($client);
        $resolved = $service->resolveWarehouseLocation('WH1', 'E-12-025', 'customer-99');

        $this->assertNotNull($resolved);
        $this->assertSame('loc-e12', $resolved['id']);
        $this->assertSame('E-12-025', $resolved['name']);
    }

    public function test_resolve_warehouse_location_skips_invalid_id_lookup_for_bin_names(): void
    {
        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldReceive('query')
            ->andReturnUsing(function (string $graphql) {
                if (strpos($graphql, 'ShipHeroLocationRecordById') !== false) {
                    $this->fail('Bin-style names should not trigger location(id) lookups.');
                }
                if (strpos($graphql, 'ShipHeroLocationCatalogResolvePage') !== false) {
                    return ['data' => ['locations' => ['data' => ['pageInfo' => ['hasNextPage' => false], 'edges' => []]]]];
                }

                return ['data' => ['locations' => ['data' => ['edges' => []]]]];
            });

        $service = new ShipHeroInventoryService($client);
        $resolved = $service->resolveWarehouseLocation('WH1', 'E-12-025', null);
        $this->assertNull($resolved);
    }
}
