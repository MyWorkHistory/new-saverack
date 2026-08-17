<?php

namespace Tests\Unit;

use App\Support\ShipHeroOrderId;
use Tests\TestCase;

class ShipHeroOrderIdTest extends TestCase
{
    public function test_legacy_id_prefers_numeric_and_decodes_graphql_order_id(): void
    {
        $this->assertSame('874518253', ShipHeroOrderId::legacyId('874518253'));
        $this->assertSame('874518253', ShipHeroOrderId::legacyId(base64_encode('Order:874518253')));
        $this->assertNull(ShipHeroOrderId::legacyId('not-an-id'));
    }

    public function test_matches_numeric_stored_id_to_graphql_order_id(): void
    {
        $graphql = base64_encode('Order:874518253');

        $this->assertTrue(ShipHeroOrderId::matches('874518253', $graphql));
        $this->assertTrue(ShipHeroOrderId::matches($graphql, '874518253'));
        $this->assertTrue(ShipHeroOrderId::matches($graphql, $graphql));
        $this->assertFalse(ShipHeroOrderId::matches('874518253', '874518254'));
    }
}
