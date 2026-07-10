<?php

namespace Tests\Unit;

use App\Services\ShipHeroClient;
use App\Services\ShipHeroOrderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ShipHeroOrderServiceFulfillmentStatusTest extends TestCase
{
    /** @var ShipHeroOrderService */
    private $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ShipHeroOrderService($this->createMock(ShipHeroClient::class));
    }

    private function normalizeFulfillmentStatus(array $node): string
    {
        $method = new ReflectionMethod(ShipHeroOrderService::class, 'normalizeFulfillmentStatus');
        $method->setAccessible(true);

        return (string) $method->invoke($this->svc, $node);
    }

    private function resolveOrderListDisplayStatus(array $node, array $holdsApi, array $tags = []): string
    {
        $method = new ReflectionMethod(ShipHeroOrderService::class, 'resolveOrderListDisplayStatus');
        $method->setAccessible(true);

        return (string) $method->invoke($this->svc, $node, $holdsApi, $tags);
    }

    public function test_accepts_common_fulfillment_status_values(): void
    {
        $this->assertSame('complete', $this->normalizeFulfillmentStatus(['fulfillment_status' => 'complete']));
        $this->assertSame('pending', $this->normalizeFulfillmentStatus(['fulfillment_status' => 'pending']));
        $this->assertSame('fulfilled', $this->normalizeFulfillmentStatus(['fulfillment_status' => 'fulfilled']));
    }

    public function test_falls_back_to_status_field_when_fulfillment_status_empty(): void
    {
        $this->assertSame(
            'shipped',
            $this->normalizeFulfillmentStatus(['fulfillment_status' => '', 'status' => 'shipped'])
        );
    }

    public function test_rejects_obvious_non_status_shop_labels(): void
    {
        $this->assertSame('', $this->normalizeFulfillmentStatus(['fulfillment_status' => 'shopify']));
        $this->assertSame('', $this->normalizeFulfillmentStatus(['fulfillment_status' => 'Antonia']));
    }

    public function test_display_status_backorder_when_has_backorder_flag(): void
    {
        $node = [
            'has_backorder' => true,
            'status' => 'Large Items',
            'fulfillment_status' => 'pending',
            'holds' => [],
            'shipping_lines' => [['method' => 'Ground', 'carrier' => 'ups']],
        ];
        $holds = [
            'fraud_hold' => false,
            'address_hold' => false,
            'shipping_method_hold' => false,
            'operator_hold' => false,
            'payment_hold' => false,
            'client_hold' => false,
        ];

        $this->assertSame('Backorder', $this->resolveOrderListDisplayStatus($node, $holds));
    }

    public function test_display_status_backorder_when_line_has_backorder_quantity(): void
    {
        $node = [
            'status' => 'Large Items',
            'fulfillment_status' => 'pending',
            'line_items' => [
                'edges' => [
                    ['node' => ['sku' => 'SKU-1', 'backorder_quantity' => 2]],
                ],
            ],
            'shipping_lines' => [['method' => 'Ground', 'carrier' => 'ups']],
        ];
        $holds = [
            'fraud_hold' => false,
            'address_hold' => false,
            'shipping_method_hold' => false,
            'operator_hold' => false,
            'payment_hold' => false,
            'client_hold' => false,
        ];

        $this->assertSame('Backorder', $this->resolveOrderListDisplayStatus($node, $holds));
    }

    public function test_display_status_hold_when_active_hold_present(): void
    {
        $node = [
            'fulfillment_status' => 'pending',
            'holds' => ['fraud_hold' => true],
            'shipping_lines' => [['method' => 'Ground', 'carrier' => 'ups']],
        ];
        $holds = [
            'fraud_hold' => true,
            'address_hold' => false,
            'shipping_method_hold' => false,
            'operator_hold' => false,
            'payment_hold' => false,
            'client_hold' => false,
        ];

        $this->assertSame('Fraud Hold', $this->resolveOrderListDisplayStatus($node, $holds));
    }

    public function test_display_status_pending_from_fulfillment_status(): void
    {
        $node = [
            'fulfillment_status' => 'pending',
            'holds' => [],
            'shipping_lines' => [],
        ];
        $holds = [
            'fraud_hold' => false,
            'address_hold' => false,
            'shipping_method_hold' => false,
            'operator_hold' => false,
            'payment_hold' => false,
            'client_hold' => false,
        ];

        $this->assertSame('Pending', $this->resolveOrderListDisplayStatus($node, $holds));
    }

    public function test_display_status_ready_to_ship_when_eligible(): void
    {
        $node = [
            'fulfillment_status' => 'pending',
            'holds' => [],
            'shipping_lines' => [['method' => 'Ground', 'carrier' => 'ups', 'title' => 'Ground']],
        ];
        $holds = [
            'fraud_hold' => false,
            'address_hold' => false,
            'shipping_method_hold' => false,
            'operator_hold' => false,
            'payment_hold' => false,
            'client_hold' => false,
        ];

        $this->assertSame('Ready To Ship', $this->resolveOrderListDisplayStatus($node, $holds));
    }

    public function test_order_qualifies_for_on_hold_queue_requires_unfulfilled_and_active_hold(): void
    {
        $this->assertTrue($this->svc->orderQualifiesForOnHoldQueue([
            'raw_fulfillment_status' => 'unfulfilled',
            'has_active_hold' => true,
        ]));
        $this->assertTrue($this->svc->orderQualifiesForOnHoldQueue([
            'raw_fulfillment_status' => 'pending',
            'has_active_hold' => true,
        ]));
        $this->assertTrue($this->svc->orderQualifiesForOnHoldQueue([
            'raw_fulfillment_status' => 'unfulfilled',
            'has_active_hold' => false,
            'hold_reason' => 'Operator Hold',
        ]));
        $this->assertFalse($this->svc->orderQualifiesForOnHoldQueue([
            'raw_fulfillment_status' => 'unfulfilled',
            'has_active_hold' => false,
            'hold_reason' => '',
        ]));
        $this->assertFalse($this->svc->orderQualifiesForOnHoldQueue([
            'raw_fulfillment_status' => 'fulfilled',
            'has_active_hold' => true,
        ]));
        $this->assertFalse($this->svc->orderQualifiesForOnHoldQueue([
            'status' => 'shipped',
            'has_active_hold' => true,
        ]));
    }

    public function test_order_qualifies_for_backorder_queue_requires_unfulfilled_and_backorder_flag(): void
    {
        $this->assertTrue($this->svc->orderQualifiesForBackorderQueue([
            'raw_fulfillment_status' => 'unfulfilled',
            'has_backorder' => true,
        ]));
        $this->assertTrue($this->svc->orderQualifiesForBackorderQueue([
            'raw_fulfillment_status' => 'pending',
            'has_backorder' => true,
        ]));
        $this->assertFalse($this->svc->orderQualifiesForBackorderQueue([
            'raw_fulfillment_status' => 'unfulfilled',
            'has_backorder' => false,
        ]));
        $this->assertFalse($this->svc->orderQualifiesForBackorderQueue([
            'raw_fulfillment_status' => 'fulfilled',
            'has_backorder' => true,
        ]));
    }

    public function test_order_qualifies_for_awaiting_queue_when_display_ready(): void
    {
        $this->assertTrue($this->svc->orderQualifiesForAwaitingQueue([
            'raw_fulfillment_status' => 'unfulfilled',
            'display_status' => 'Ready To Ship',
            'has_backorder' => false,
            'has_active_hold' => false,
        ]));
    }
}
