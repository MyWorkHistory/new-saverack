<?php

namespace Tests\Unit;

use App\Services\ShipHeroClient;
use App\Services\ShipHeroOrderService;
use PHPUnit\Framework\TestCase;

final class ShipHeroOrderServiceCountShipmentsTest extends TestCase
{
    public function test_count_shipments_excludes_off_platform_void_and_out_of_range(): void
    {
        $client = $this->createMock(ShipHeroClient::class);
        $client->expects($this->once())
            ->method('query')
            ->willReturn([
                'data' => [
                    'shipments' => [
                        'data' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 's1',
                                        'created_date' => '2026-05-27T15:00:00-04:00',
                                        'shipped_off_shiphero' => false,
                                        'shipping_labels' => [['status' => 'valid']],
                                    ],
                                ],
                                [
                                    'node' => [
                                        'id' => 's2',
                                        'created_date' => '2026-05-27T16:00:00-04:00',
                                        'shipped_off_shiphero' => true,
                                        'shipping_labels' => [['status' => 'valid']],
                                    ],
                                ],
                                [
                                    'node' => [
                                        'id' => 's3',
                                        'created_date' => '2026-05-26T23:00:00-04:00',
                                        'shipped_off_shiphero' => false,
                                        'shipping_labels' => [['status' => 'valid']],
                                    ],
                                ],
                                [
                                    'node' => [
                                        'id' => 's4',
                                        'created_date' => '2026-05-27T17:00:00-04:00',
                                        'shipped_off_shiphero' => false,
                                        'shipping_labels' => [['status' => 'voided']],
                                    ],
                                ],
                                [
                                    'node' => [
                                        'id' => 's1',
                                        'created_date' => '2026-05-27T15:00:00-04:00',
                                        'shipped_off_shiphero' => false,
                                        'shipping_labels' => [['status' => 'valid']],
                                    ],
                                ],
                            ],
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        ],
                    ],
                ],
            ]);

        $svc = new ShipHeroOrderService($client);
        $out = $svc->countShipments([
            'customer_account_id' => 'acct-esas',
            'date_from' => '2026-05-27T00:00:00-04:00',
            'date_to' => '2026-05-27T23:59:59-04:00',
            'timezone' => 'America/New_York',
        ]);

        $this->assertSame(1, $out['count']);
        $this->assertFalse($out['truncated']);
    }

    public function test_count_shipments_counts_non_void_labels_per_shipment(): void
    {
        $client = $this->createMock(ShipHeroClient::class);
        $client->expects($this->once())
            ->method('query')
            ->willReturn([
                'data' => [
                    'shipments' => [
                        'data' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 's1',
                                        'created_date' => '2026-05-27T15:00:00-04:00',
                                        'shipped_off_shiphero' => false,
                                        'shipping_labels' => [
                                            ['status' => 'valid'],
                                            ['status' => 'valid'],
                                            ['status' => 'voided'],
                                        ],
                                    ],
                                ],
                            ],
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        ],
                    ],
                ],
            ]);

        $svc = new ShipHeroOrderService($client);
        $out = $svc->countShipments([
            'customer_account_id' => 'acct-esas',
            'date_from' => '2026-05-27T00:00:00-04:00',
            'date_to' => '2026-05-27T23:59:59-04:00',
            'timezone' => 'America/New_York',
        ]);

        $this->assertSame(2, $out['count']);
    }
}
