<?php

namespace Tests\Unit;

use App\Services\ShipHeroOrderService;
use PHPUnit\Framework\TestCase;

final class ShipHeroOrderServiceUserHoldTest extends TestCase
{
    public function test_order_removable_hold_keys_includes_mutation_key(): void
    {
        $keys = ShipHeroOrderService::orderRemovableHoldKeys();

        $this->assertContains('fraud_hold', $keys);
        $this->assertContains('payment_hold', $keys);
        $this->assertContains('operator_hold', $keys);
        $this->assertSame('operator_hold', ShipHeroOrderService::ORDER_USER_HOLD_MUTATION_KEY);
        $this->assertSame('client_hold', ShipHeroOrderService::ORDER_USER_HOLD_DISPLAY_KEY);
    }

    public function test_normalize_user_hold_mutation_flags_maps_client_to_operator(): void
    {
        $svc = new ShipHeroOrderService(
            $this->createMock(\App\Services\ShipHeroClient::class)
        );

        $normalized = $svc->normalizeUserHoldMutationFlags(['client_hold' => true]);

        $this->assertTrue($normalized['operator_hold']);
        $this->assertArrayNotHasKey('client_hold', $normalized);
    }

    public function test_order_holds_only_user_hold_active_when_only_operator_hold_on(): void
    {
        $svc = new ShipHeroOrderService(
            $this->createMock(\App\Services\ShipHeroClient::class)
        );

        $this->assertTrue($svc->orderHoldsOnlyUserHoldActive([
            'operator_hold' => true,
            'fraud_hold' => false,
            'address_hold' => false,
            'payment_hold' => false,
            'client_hold' => false,
            'shipping_method_hold' => false,
        ]));

        $this->assertFalse($svc->orderHoldsOnlyUserHoldActive([
            'operator_hold' => true,
            'fraud_hold' => true,
        ]));
    }

    public function test_order_holds_only_client_hold_active_when_only_client_hold_on(): void
    {
        $svc = new ShipHeroOrderService(
            $this->createMock(\App\Services\ShipHeroClient::class)
        );

        $this->assertTrue($svc->orderHoldsOnlyClientHoldActive([
            'client_hold' => true,
            'operator_hold' => false,
            'fraud_hold' => false,
            'address_hold' => false,
            'payment_hold' => false,
            'shipping_method_hold' => false,
        ]));

        $this->assertFalse($svc->orderHoldsOnlyClientHoldActive([
            'client_hold' => true,
            'operator_hold' => true,
        ]));
    }
}
