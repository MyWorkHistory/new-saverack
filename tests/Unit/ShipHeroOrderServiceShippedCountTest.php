<?php

namespace Tests\Unit;

use App\Services\ShipHeroOrderService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ShipHeroOrderServiceShippedCountTest extends TestCase
{
    public function test_extract_shipment_ship_dates_counts_one_per_shipment_not_per_label(): void
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
                    'shipping_labels' => [
                        ['status' => 'valid', 'created_date' => '2026-05-27T16:00:00Z'],
                    ],
                ],
            ],
        ]);

        $this->assertCount(2, $dates);
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
}
