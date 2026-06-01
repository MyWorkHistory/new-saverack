<?php

namespace Tests\Unit;

use App\Services\ShipHeroClient;
use App\Services\ShipHeroInventoryService;
use Tests\TestCase;

class ShipHeroProductCreateTest extends TestCase
{
    public function test_create_product_returns_id_and_image(): void
    {
        $client = $this->createMock(ShipHeroClient::class);
        $client->method('query')->willReturnCallback(function (string $graphql, array $variables = []) {
            if (str_contains($graphql, 'ShipHeroWarehouses')) {
                return [
                    'data' => [
                        'account' => [
                            'data' => [
                                'warehouses' => [
                                    ['id' => 'wh-1', 'legacy_id' => 1, 'identifier' => 'Lakeland', 'address' => ['name' => 'Lakeland']],
                                ],
                            ],
                        ],
                    ],
                ];
            }

            $this->assertSame('NEW-SKU-1', $variables['data']['sku'] ?? null);
            $this->assertSame('Test Widget', $variables['data']['name'] ?? null);
            $this->assertSame('cust-99', $variables['data']['customer_account_id'] ?? null);

            return [
                'data' => [
                    'product_create' => [
                        'request_id' => 'req-1',
                        'product' => [
                            'id' => 'prod-new-1',
                            'sku' => 'NEW-SKU-1',
                            'name' => 'Test Widget',
                            'thumbnail' => 'https://cdn.example/thumb.jpg',
                        ],
                    ],
                ],
            ];
        });

        $service = new ShipHeroInventoryService($client);
        $created = $service->createProduct('cust-99', 'NEW-SKU-1', 'Test Widget');

        $this->assertSame('prod-new-1', $created['id']);
        $this->assertSame('NEW-SKU-1', $created['sku']);
        $this->assertSame('https://cdn.example/thumb.jpg', $created['image_url']);
    }

    public function test_minimal_product_from_asn_line_includes_flag(): void
    {
        $service = app(ShipHeroInventoryService::class);
        $payload = $service->minimalProductFromAsnLine('SKU-X', 'Line Name', null, null);

        $this->assertTrue($payload['asn_line_only']);
        $this->assertSame('SKU-X', $payload['sku']);
        $this->assertSame('Line Name', $payload['name']);
        $this->assertSame(0, $payload['metrics']['on_hand']);
    }
}
