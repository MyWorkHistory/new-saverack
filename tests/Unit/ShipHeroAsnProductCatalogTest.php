<?php

namespace Tests\Unit;

use App\Services\ShipHeroClient;
use App\Services\ShipHeroInventoryService;
use PHPUnit\Framework\TestCase;

class ShipHeroAsnProductCatalogTest extends TestCase
{
    public function test_list_asn_product_catalog_filters_inactive_and_kits(): void
    {
        $client = new class extends ShipHeroClient
        {
            public function query(string $graphql, array $variables = [], bool $allowTokenRetry = true, array $options = []): array
            {
                return [
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
                                            'id' => 'p1',
                                            'sku' => 'A',
                                            'name' => 'Active Simple',
                                            'active' => true,
                                            'kit' => false,
                                            'kit_build' => false,
                                            'barcode' => '111',
                                        ],
                                    ],
                                    [
                                        'node' => [
                                            'id' => 'p2',
                                            'sku' => 'B',
                                            'name' => 'Inactive',
                                            'active' => false,
                                            'kit' => false,
                                            'kit_build' => false,
                                            'barcode' => '',
                                        ],
                                    ],
                                    [
                                        'node' => [
                                            'id' => 'p3',
                                            'sku' => 'C',
                                            'name' => 'Kit',
                                            'active' => true,
                                            'kit' => true,
                                            'kit_build' => false,
                                            'barcode' => '',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }

            public function accessToken(): string
            {
                return 'test';
            }
        };

        $svc = new ShipHeroInventoryService($client);
        $out = $svc->listAsnProductCatalog('cust-1', 100, 5);

        $this->assertFalse($out['truncated']);
        $this->assertCount(1, $out['products']);
        $this->assertSame('A', $out['products'][0]['sku']);
        $this->assertSame('111', $out['products'][0]['barcode']);
    }
}
