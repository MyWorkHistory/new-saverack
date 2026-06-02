<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Services\ShipHeroClient;
use App\Services\ShipHeroCustomerAccountService;
use Tests\TestCase;

final class ShipHeroCustomerAccountHideOrdersTest extends TestCase
{
    public function test_should_hide_orders_from_app_for_non_active_statuses(): void
    {
        $service = new ShipHeroCustomerAccountService($this->createMock(ShipHeroClient::class));

        $this->assertFalse($service->shouldHideOrdersFromApp(ClientAccount::STATUS_ACTIVE));
        $this->assertTrue($service->shouldHideOrdersFromApp(ClientAccount::STATUS_PENDING));
        $this->assertTrue($service->shouldHideOrdersFromApp(ClientAccount::STATUS_PAUSED));
        $this->assertTrue($service->shouldHideOrdersFromApp(ClientAccount::STATUS_INACTIVE));
    }

    public function test_sync_skips_when_shiphero_customer_account_id_missing(): void
    {
        $service = new ShipHeroCustomerAccountService($this->createMock(ShipHeroClient::class));
        $account = new ClientAccount([
            'status' => ClientAccount::STATUS_PAUSED,
            'shiphero_customer_account_id' => null,
        ]);

        $result = $service->syncHideOrdersFromApp($account);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('skipped', (string) $result['message']);
    }
}
