<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\ShipHeroOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class OrderQueueIndexListApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function ordersViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'orders.view'],
            ['label' => 'View orders', 'module' => 'orders']
        );
    }

    private function staffWithOrdersView(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->sync([$this->ordersViewPermission()->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_shipped_queue_list_uses_index_only_and_never_calls_shiphero_when_index_empty(): void
    {
        $this->staffWithOrdersView();

        $account = ClientAccount::create([
            'company_name' => 'Index Only Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-index-only-1',
        ]);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('listShippedOrders')->never();
        $mock->shouldReceive('listOrders')->never();
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $this->getJson('/api/orders?tab=shipped&client_account_id='.$account->id
            .'&order_date_from=2026-07-01&order_date_to=2026-07-01')
            ->assertOk()
            ->assertJsonPath('rows', [])
            ->assertJsonPath('meta.from_index', true)
            ->assertJsonPath('meta.refresh_pending', true);
    }
}
