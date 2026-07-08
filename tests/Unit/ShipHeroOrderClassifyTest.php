<?php

namespace Tests\Unit;

use App\Services\ShipHeroOrderService;
use Tests\TestCase;

class ShipHeroOrderClassifyTest extends TestCase
{
    public function test_classify_shipped_order(): void
    {
        $service = app(ShipHeroOrderService::class);
        $tab = $service->classifyOrderQueueTab([
            'status' => 'fulfilled',
            'raw_fulfillment_status' => 'fulfilled',
            'raw_status' => '',
            'has_backorder' => false,
            'has_active_hold' => false,
            'display_status' => 'Fulfilled',
        ]);

        $this->assertSame('shipped', $tab);
    }

    public function test_classify_ready_to_ship_order(): void
    {
        $service = app(ShipHeroOrderService::class);
        $tab = $service->classifyOrderQueueTab([
            'status' => 'unfulfilled',
            'raw_fulfillment_status' => 'unfulfilled',
            'raw_status' => '',
            'has_backorder' => false,
            'has_active_hold' => false,
            'display_status' => 'Ready To Ship',
            'method' => 'Ground',
            'holds' => [],
        ]);

        $this->assertSame('awaiting', $tab);
    }

    public function test_classify_canceled_order_returns_null(): void
    {
        $service = app(ShipHeroOrderService::class);
        $tab = $service->classifyOrderQueueTab([
            'status' => 'canceled',
            'raw_fulfillment_status' => 'canceled',
            'raw_status' => '',
            'has_backorder' => false,
            'has_active_hold' => false,
            'display_status' => 'Canceled',
        ]);

        $this->assertNull($tab);
    }
}
