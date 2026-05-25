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
        $this->assertContains(ShipHeroOrderService::ORDER_USER_HOLD_KEY, $keys);
    }

    public function test_order_holds_only_operator_hold_active_when_only_user_hold_on(): void
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
