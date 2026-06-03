<?php

namespace Tests\Unit;

use App\Services\ShipHeroClient;
use App\Services\ShipHeroOrderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ShipHeroOrderServiceFulfillmentStatusTest extends TestCase
{
    private function normalizeFulfillmentStatus(array $node): string
    {
        $svc = new ShipHeroOrderService($this->createMock(ShipHeroClient::class));
        $method = new ReflectionMethod(ShipHeroOrderService::class, 'normalizeFulfillmentStatus');
        $method->setAccessible(true);

        return (string) $method->invoke($svc, $node);
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
}
