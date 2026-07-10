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

    public function test_classify_on_hold_requires_active_hold(): void
    {
        $service = app(ShipHeroOrderService::class);
        $tab = $service->classifyOrderQueueTab([
            'raw_fulfillment_status' => 'unfulfilled',
            'has_backorder' => false,
            'has_active_hold' => true,
        ]);

        $this->assertSame('on_hold', $tab);
    }

    public function test_classify_backorder_requires_unfulfilled_backorder_flag(): void
    {
        $service = app(ShipHeroOrderService::class);
        $tab = $service->classifyOrderQueueTab([
            'raw_fulfillment_status' => 'unfulfilled',
            'has_backorder' => true,
            'has_active_hold' => false,
        ]);

        $this->assertSame('backorder', $tab);
    }

    public function test_classify_can_return_both_backorder_and_on_hold_tabs(): void
    {
        $service = app(ShipHeroOrderService::class);
        $tabs = $service->classifyOrderQueueTabs([
            'raw_fulfillment_status' => 'unfulfilled',
            'has_backorder' => true,
            'has_active_hold' => true,
        ]);

        $this->assertSame(['backorder', 'on_hold'], $tabs);
    }
}
