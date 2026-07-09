<?php

namespace Tests\Unit;

use App\Services\ShipHeroWebhookPayloadResolver;
use App\Services\ShipHeroOrderService;
use Mockery;
use Tests\TestCase;

class ShipHeroWebhookPayloadResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_extract_skus_from_inventory_update_payload(): void
    {
        $resolver = new ShipHeroWebhookPayloadResolver(Mockery::mock(ShipHeroOrderService::class));

        $skus = $resolver->extractSkus([
            'webhook_type' => 'Inventory Update',
            'inventory' => [
                ['sku' => 'SKU-A'],
                ['sku' => 'SKU-B'],
                ['sku' => 'SKU-A'],
            ],
        ]);

        $this->assertSame(['SKU-A', 'SKU-B'], $skus);
    }

    public function test_extract_skus_from_inventory_change_payload(): void
    {
        $resolver = new ShipHeroWebhookPayloadResolver(Mockery::mock(ShipHeroOrderService::class));

        $skus = $resolver->extractSkus([
            'webhook_type' => 'Inventory Change',
            'sku' => 'SKU-CHANGE-1',
        ]);

        $this->assertSame(['SKU-CHANGE-1'], $skus);
    }
}
