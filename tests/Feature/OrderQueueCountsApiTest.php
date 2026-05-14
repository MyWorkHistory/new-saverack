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

class OrderQueueCountsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function inventoryViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'View inventory', 'module' => 'inventory']
        );
    }

    private function makeAccountWithShipHero(): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'Queue Count Test Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-queue-test-1',
        ]);
    }

    public function test_queue_counts_returns_counts_for_authorized_portal_user(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('countOrders')
            ->times(4)
            ->andReturnUsing(static function (array $filters): array {
                return match ($filters['tab'] ?? '') {
                    'awaiting' => ['count' => 11, 'truncated' => false],
                    'on_hold' => ['count' => 2, 'truncated' => false],
                    'backorder' => ['count' => 1, 'truncated' => false],
                    'shipped' => ['count' => 44, 'truncated' => true],
                    default => ['count' => 0, 'truncated' => false],
                };
            });
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $response = $this->getJson('/api/orders/queue-counts?client_account_id='.$account->id);

        $response->assertOk()
            ->assertJsonPath('ready_to_ship', 11)
            ->assertJsonPath('on_hold', 2)
            ->assertJsonPath('backorder', 1)
            ->assertJsonPath('shipped', 44)
            ->assertJsonPath('truncated', true)
            ->assertJsonStructure([
                'awaiting_order_date_from',
                'awaiting_order_date_to',
                'open_queue_order_date_from',
                'open_queue_order_date_to',
                'shipped_order_date_from',
                'shipped_order_date_to',
                'cached_at',
            ]);
    }

    public function test_queue_counts_forbidden_when_portal_user_targets_another_account(): void
    {
        $accountA = $this->makeAccountWithShipHero();
        $accountB = ClientAccount::create([
            'company_name' => 'Other Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-other-2',
        ]);
        $user = User::factory()->create(['client_account_id' => $accountA->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('countOrders')->never();
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $this->getJson('/api/orders/queue-counts?client_account_id='.$accountB->id)
            ->assertForbidden();
    }

    public function test_guest_cannot_access_queue_counts(): void
    {
        $account = $this->makeAccountWithShipHero();

        $this->getJson('/api/orders/queue-counts?client_account_id='.$account->id)
            ->assertUnauthorized();
    }
}
