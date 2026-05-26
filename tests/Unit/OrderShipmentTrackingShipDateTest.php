<?php

namespace Tests\Unit;

use App\Support\OrderShipmentTracking;
use PHPUnit\Framework\TestCase;

final class OrderShipmentTrackingShipDateTest extends TestCase
{
    public function test_resolve_ship_date_uses_latest_non_void_label(): void
    {
        $iso = OrderShipmentTracking::resolveShipDateIso([
            'shipments' => [
                [
                    'created_date' => '2026-05-10T08:00:00Z',
                    'shipping_labels' => [
                        ['status' => 'void', 'created_date' => '2026-05-18T12:00:00Z'],
                        ['status' => 'printed', 'created_date' => '2026-05-17T15:30:00Z'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('2026-05-17T15:30:00+00:00', $iso);
    }

    public function test_resolve_ship_date_falls_back_to_updated_at(): void
    {
        $iso = OrderShipmentTracking::resolveShipDateIso([
            'updated_at' => '2026-05-18T09:00:00Z',
        ]);

        $this->assertSame('2026-05-18T09:00:00+00:00', $iso);
    }

    public function test_resolve_ship_date_returns_null_without_dates(): void
    {
        $this->assertNull(OrderShipmentTracking::resolveShipDateIso([]));
    }
}
