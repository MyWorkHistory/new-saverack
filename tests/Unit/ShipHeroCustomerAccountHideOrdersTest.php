<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Services\ShipHeroClient;
use App\Services\ShipHeroCustomerAccountService;
use Illuminate\Support\Facades\Cache;
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

    public function test_sync_fails_when_api_not_configured_but_customer_id_present(): void
    {
        config([
            'services.shiphero.customer_account_update_mutation' => null,
            'services.shiphero.customer_account_hide_orders_field' => null,
        ]);
        Cache::forget('shiphero.customer_account.hide_orders_sync_config');

        $service = new ShipHeroCustomerAccountService($this->createMock(ShipHeroClient::class));
        $account = new ClientAccount([
            'status' => ClientAccount::STATUS_PAUSED,
            'shiphero_customer_account_id' => '92441',
        ]);

        $result = $service->syncHideOrdersFromApp($account);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('hide-orders', (string) $result['message']);
    }
}
