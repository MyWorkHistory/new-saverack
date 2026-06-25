<?php

namespace Tests\Unit;

use App\Services\ShipHeroClient;
use App\Services\ShipHeroInventoryService;
use Mockery;
use Tests\TestCase;

class NormalizeShipHeroCustomerAccountIdTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_normalizes_graphql_account_token_to_legacy_numeric_id(): void
    {
        $service = new ShipHeroInventoryService(Mockery::mock(ShipHeroClient::class));

        $this->assertSame(
            '97302',
            $service->normalizeShipHeroCustomerAccountId('QWNjb3VudDo5NzMwMg==')
        );
    }

    public function test_preserves_numeric_customer_account_id(): void
    {
        $service = new ShipHeroInventoryService(Mockery::mock(ShipHeroClient::class));

        $this->assertSame('92640', $service->normalizeShipHeroCustomerAccountId('92640'));
    }
}
