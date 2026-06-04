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

    public function test_sync_skips_when_hide_orders_api_not_available(): void
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

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('skipped', (string) $result['message']);
    }

    public function test_sync_skips_when_env_mutation_not_in_shiphero_schema(): void
    {
        config([
            'services.shiphero.customer_account_update_mutation' => 'customer_account_update',
            'services.shiphero.customer_account_update_input_type' => 'UpdateCustomerAccountInput',
            'services.shiphero.customer_account_hide_orders_field' => 'hide_orders_from_app',
        ]);
        Cache::forget('shiphero.customer_account.hide_orders_sync_config');

        $client = $this->createMock(ShipHeroClient::class);
        $client->method('queryRawDiagnostic')->willReturn([
            'status' => 200,
            'body' => json_encode([
                'errors' => [
                    ['message' => 'Cannot query field "customer_account_update" on type "Mutation".'],
                ],
            ]),
        ]);

        $service = new ShipHeroCustomerAccountService($client);
        $account = new ClientAccount([
            'status' => ClientAccount::STATUS_PAUSED,
            'shiphero_customer_account_id' => '92441',
        ]);

        $result = $service->syncHideOrdersFromApp($account);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('skipped', (string) $result['message']);
    }
}
