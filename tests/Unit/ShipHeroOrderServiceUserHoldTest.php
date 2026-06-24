<?php

namespace Tests\Unit;

use App\Services\ShipHeroOrderService;
use PHPUnit\Framework\TestCase;

final class ShipHeroOrderServiceUserHoldTest extends TestCase
{
    public function test_humanize_hold_error_message_maps_client_hold_to_user_hold(): void
    {
        $humanized = ShipHeroOrderService::humanizeHoldErrorMessage(
            'ShipHero: 3PL cannot set a client hold on an order'
        );

        $this->assertStringContainsString('User Hold', $humanized);
        $this->assertStringNotContainsString('client hold', strtolower($humanized));
    }

    public function test_order_removable_hold_keys_includes_client_hold_display_key(): void
    {
        $keys = ShipHeroOrderService::orderRemovableHoldKeys();

        $this->assertContains('fraud_hold', $keys);
        $this->assertContains('payment_hold', $keys);
        $this->assertContains('client_hold', $keys);
        $this->assertSame('operator_hold', ShipHeroOrderService::ORDER_USER_HOLD_MUTATION_KEY);
        $this->assertSame('client_hold', ShipHeroOrderService::ORDER_USER_HOLD_DISPLAY_KEY);
        $this->assertSame('saverack:user_hold', ShipHeroOrderService::ORDER_USER_HOLD_TAG);
        $this->assertNotContains('operator_hold', $keys);
    }

    public function test_normalize_user_hold_mutation_flags_maps_client_hold_to_operator_hold(): void
    {
        $svc = new ShipHeroOrderService(
            $this->createMock(\App\Services\ShipHeroClient::class)
        );

        $normalized = $svc->normalizeUserHoldMutationFlags(['client_hold' => true]);

        $this->assertTrue($normalized['operator_hold']);
        $this->assertArrayNotHasKey('client_hold', $normalized);
    }

    public function test_normalize_user_hold_mutation_flags_strips_operator_hold_from_client_requests(): void
    {
        $svc = new ShipHeroOrderService(
            $this->createMock(\App\Services\ShipHeroClient::class)
        );

        $normalized = $svc->normalizeUserHoldMutationFlags([
            'client_hold' => true,
            'operator_hold' => true,
            'fraud_hold' => true,
        ]);

        $this->assertTrue($normalized['operator_hold']);
        $this->assertTrue($normalized['fraud_hold']);
        $this->assertArrayNotHasKey('client_hold', $normalized);
    }

    public function test_order_holds_only_user_hold_active_when_operator_hold_and_tag_present(): void
    {
        $svc = new ShipHeroOrderService(
            $this->createMock(\App\Services\ShipHeroClient::class)
        );

        $holds = [
            'client_hold' => false,
            'operator_hold' => true,
            'fraud_hold' => false,
            'address_hold' => false,
            'payment_hold' => false,
            'shipping_method_hold' => false,
        ];
        $tags = ['saverack:user_hold'];

        $this->assertTrue($svc->orderHoldsOnlyUserHoldActive($holds, $tags));

        $this->assertFalse($svc->orderHoldsOnlyUserHoldActive([
            'operator_hold' => true,
            'fraud_hold' => true,
        ], $tags));

        $this->assertFalse($svc->orderHoldsOnlyUserHoldActive($holds, []));
    }

    public function test_order_holds_only_operator_hold_active_when_only_operator_hold_without_tag(): void
    {
        $svc = new ShipHeroOrderService(
            $this->createMock(\App\Services\ShipHeroClient::class)
        );

        $holds = [
            'operator_hold' => true,
            'client_hold' => false,
            'fraud_hold' => false,
            'address_hold' => false,
            'payment_hold' => false,
            'shipping_method_hold' => false,
        ];

        $this->assertTrue($svc->orderHoldsOnlyOperatorHoldActive($holds, []));

        $this->assertFalse($svc->orderHoldsOnlyOperatorHoldActive($holds, ['saverack:user_hold']));

        $this->assertFalse($svc->orderHoldsOnlyOperatorHoldActive([
            'operator_hold' => true,
            'client_hold' => true,
        ], []));
    }

    public function test_order_holds_only_client_hold_active_matches_tagged_user_hold(): void
    {
        $svc = new ShipHeroOrderService(
            $this->createMock(\App\Services\ShipHeroClient::class)
        );

        $holds = [
            'client_hold' => false,
            'operator_hold' => true,
            'fraud_hold' => false,
            'address_hold' => false,
            'payment_hold' => false,
            'shipping_method_hold' => false,
        ];
        $tags = ['saverack:user_hold'];

        $this->assertTrue($svc->orderHoldsOnlyClientHoldActive($holds, $tags));
        $this->assertTrue($svc->orderHoldsOnlyUserHoldActive($holds, $tags));
    }
}
