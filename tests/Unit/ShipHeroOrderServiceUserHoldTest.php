<?php

namespace Tests\Unit;

use App\Services\ShipHeroOrderService;
use PHPUnit\Framework\TestCase;

final class ShipHeroOrderServiceUserHoldTest extends TestCase
{
    public function test_order_removable_hold_keys_includes_user_hold(): void
    {
        $keys = ShipHeroOrderService::orderRemovableHoldKeys();

        $this->assertContains('fraud_hold', $keys);
        $this->assertContains('payment_hold', $keys);
        $this->assertContains('client_hold', $keys);
        $this->assertSame('client_hold', ShipHeroOrderService::ORDER_USER_HOLD_KEY);
    }

    public function test_order_holds_only_user_hold_active_when_only_client_hold_on(): void
    {
        $svc = new ShipHeroOrderService(
            $this->createMock(\App\Services\ShipHeroClient::class)
        );

        $this->assertTrue($svc->orderHoldsOnlyUserHoldActive([
            'operator_hold' => false,
            'fraud_hold' => false,
            'address_hold' => false,
            'payment_hold' => false,
            'client_hold' => true,
            'shipping_method_hold' => false,
        ]));

        $this->assertFalse($svc->orderHoldsOnlyUserHoldActive([
            'client_hold' => true,
            'fraud_hold' => true,
        ]));
    }

    public function test_order_holds_only_operator_hold_active_when_only_operator_hold_on(): void
    {
        $svc = new ShipHeroOrderService(
            $this->createMock(\App\Services\ShipHeroClient::class)
        );

        $this->assertTrue($svc->orderHoldsOnlyOperatorHoldActive([
            'operator_hold' => true,
            'fraud_hold' => false,
            'address_hold' => false,
            'payment_hold' => false,
            'client_hold' => false,
            'shipping_method_hold' => false,
        ]));

        $this->assertFalse($svc->orderHoldsOnlyOperatorHoldActive([
            'operator_hold' => true,
            'fraud_hold' => true,
        ]));
    }
}
