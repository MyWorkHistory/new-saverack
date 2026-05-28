<?php

namespace Tests\Unit;

use App\Services\ShipHeroOrderService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ShipHeroOrderServiceShippedCountTest extends TestCase
{
    public function test_extract_shipment_ship_dates_uses_shipment_created_date_once_per_shipment(): void
    {
        $svc = new ShipHeroOrderService($this->createMock(\App\Services\ShipHeroClient::class));
        $method = new ReflectionMethod(ShipHeroOrderService::class, 'extractShipmentShipDates');
        $method->setAccessible(true);

        $dates = $method->invoke($svc, [
            'shipments' => [
                [
                    'created_date' => '2026-05-27T10:00:00Z',
                    'shipping_labels' => [
                        ['status' => 'valid', 'created_date' => '2026-05-27T12:00:00Z'],
                        ['status' => 'valid', 'created_date' => '2026-05-27T14:00:00Z'],
                    ],
                ],
                [
                    'created_date' => '2026-05-27T16:00:00Z',
                    'shipped_off_shiphero' => true,
                    'shipping_labels' => [
                        ['status' => 'valid', 'created_date' => '2026-05-27T16:00:00Z'],
                    ],
                ],
                [
                    'created_date' => '2026-05-27T18:00:00Z',
                    'shipping_labels' => [
                        ['status' => 'valid', 'created_date' => '2026-05-27T19:00:00Z'],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            ['2026-05-27T10:00:00Z', '2026-05-27T18:00:00Z'],
            $dates
        );
    }

    public function test_row_shipment_count_in_range_uses_est_day_boundaries(): void
    {
        $svc = new ShipHeroOrderService($this->createMock(\App\Services\ShipHeroClient::class));
        $countMethod = new ReflectionMethod(ShipHeroOrderService::class, 'rowShipmentCountInRange');
        $countMethod->setAccessible(true);

        $tz = 'America/New_York';
        $from = Carbon::parse('2026-05-27', $tz)->startOfDay();
        $to = Carbon::parse('2026-05-27', $tz)->endOfDay();

        $row = [
            'shipment_dates' => [
                '2026-05-27T02:00:00Z',
                '2026-05-28T02:00:00Z',
            ],
        ];

        $count = $countMethod->invoke($svc, $row, $from, $to);

        $this->assertSame(1, $count);
    }

    public function test_shipment_counts_for_dashboard_excludes_off_shiphero_and_void_only(): void
    {
        $svc = new ShipHeroOrderService($this->createMock(\App\Services\ShipHeroClient::class));
        $method = new ReflectionMethod(ShipHeroOrderService::class, 'shipmentCountsForDashboard');
        $method->setAccessible(true);

        $tz = 'America/New_York';
        $from = Carbon::parse('2026-05-27', $tz)->startOfDay();
        $to = Carbon::parse('2026-05-27', $tz)->endOfDay();

        $inRange = $method->invoke($svc, [
            'shipped_off_shiphero' => false,
            'created_date' => '2026-05-27T15:00:00Z',
            'shipping_labels' => [['status' => 'valid']],
        ], $from, $to);
        $this->assertTrue($inRange);

        $offPlatform = $method->invoke($svc, [
            'shipped_off_shiphero' => true,
            'created_date' => '2026-05-27T15:00:00Z',
            'shipping_labels' => [['status' => 'valid']],
        ], $from, $to);
        $this->assertFalse($offPlatform);

        $voidOnly = $method->invoke($svc, [
            'shipped_off_shiphero' => false,
            'created_date' => '2026-05-27T15:00:00Z',
            'shipping_labels' => [['status' => 'void']],
        ], $from, $to);
        $this->assertFalse($voidOnly);
    }

    public function test_parse_shipments_connection_includes_order_id_when_requested(): void
    {
        $svc = new ShipHeroOrderService($this->createMock(\App\Services\ShipHeroClient::class));
        $method = new ReflectionMethod(ShipHeroOrderService::class, 'parseShipHeroShipmentsConnection');
        $method->setAccessible(true);

        $json = [
            'data' => [
                'shipments' => [
                    'data' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'ship-1',
                                    'order_id' => 'order-abc',
                                    'created_date' => '2026-05-27T10:00:00Z',
                                    'shipped_off_shiphero' => false,
                                    'shipping_labels' => [],
                                ],
                            ],
                        ],
                        'pageInfo' => [
                            'hasNextPage' => false,
                            'endCursor' => null,
                        ],
                    ],
                ],
            ],
        ];

        $parsed = $method->invoke($svc, $json, true);
        $this->assertSame('order-abc', $parsed['rows'][0]['order_id'] ?? null);
    }

    public function test_shipment_created_is_after_picks_latest_timestamp(): void
    {
        $svc = new ShipHeroOrderService($this->createMock(\App\Services\ShipHeroClient::class));
        $method = new ReflectionMethod(ShipHeroOrderService::class, 'shipmentCreatedIsAfter');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($svc, '2026-05-28T10:00:00Z', '2026-05-27T10:00:00Z'));
        $this->assertFalse($method->invoke($svc, '2026-05-26T10:00:00Z', '2026-05-27T10:00:00Z'));
    }
}
