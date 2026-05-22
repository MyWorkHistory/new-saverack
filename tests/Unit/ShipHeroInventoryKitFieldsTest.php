<?php

namespace Tests\Unit;

use App\Services\ShipHeroClient;
use App\Services\ShipHeroInventoryService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ShipHeroInventoryKitFieldsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_normalize_kit_components_supports_edges_shape(): void
    {
        $service = app(ShipHeroInventoryService::class);
        $method = new ReflectionMethod($service, 'normalizeKitComponents');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'edges' => [
                ['node' => ['sku' => 'PART-1', 'quantity' => 2]],
                ['node' => ['sku' => 'PART-2', 'quantity' => 1]],
            ],
        ]);

        $this->assertSame([
            ['sku' => 'PART-1', 'quantity' => 2.0],
            ['sku' => 'PART-2', 'quantity' => 1.0],
        ], $result);
    }

    public function test_resolve_kit_components_falls_back_to_components_nodes(): void
    {
        $service = app(ShipHeroInventoryService::class);
        $method = new ReflectionMethod($service, 'resolveKitComponentsFromProductData');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'kit_components' => [],
            'components' => [
                ['sku' => 'CDK-001', 'name' => 'Component'],
            ],
        ]);

        $this->assertSame([
            ['sku' => 'CDK-001', 'quantity' => 1.0],
        ], $result);
    }

    public function test_find_parent_kits_for_component_sku(): void
    {
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
                                        'sku' => 'KIT-BOX',
                                        'name' => 'Kit Box',
                                        'kit' => true,
                                        'kit_build' => false,
                                        'kit_components' => [
                                            ['sku' => 'CDK-001', 'quantity' => 3],
                                        ],
                                    ],
                                ],
                                [
                                    'node' => [
                                        'sku' => 'PLAIN',
                                        'name' => 'Plain',
                                        'kit' => false,
                                        'kit_build' => false,
                                        'kit_components' => [
                                            ['sku' => 'CDK-001', 'quantity' => 1],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $service = new ShipHeroInventoryService($client);
        $matches = $service->findParentKitsForComponentSku('cust-1', 'CDK-001');

        $this->assertCount(1, $matches);
        $this->assertSame('KIT-BOX', $matches[0]['sku']);
        $this->assertSame(3.0, $matches[0]['quantity']);
    }
}
