<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\ShipHeroOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    private function mockShipHeroCounts(): void
    {
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
    }

    public function test_queue_counts_builds_sync_when_cache_cold(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);
        $this->mockShipHeroCounts();

        $response = $this->getJson('/api/orders/queue-counts?client_account_id='.$account->id);

        $response->assertOk()
            ->assertJsonPath('ready_to_ship', 11)
            ->assertJsonPath('on_hold', 2)
            ->assertJsonPath('backorder', 1)
            ->assertJsonPath('shipped', 44)
            ->assertJsonPath('truncated', true)
            ->assertJsonPath('refresh_pending', false);
    }

    public function test_queue_counts_returns_cached_payload_without_rebuilding(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $service = app(\App\Services\PortalQueueCountsService::class);
        $context = $service->contextForAccount($account);
        Cache::put($context['cache_key'], [
            'ready_to_ship' => 99,
            'on_hold' => 0,
            'backorder' => 0,
            'shipped' => 0,
            'truncated' => false,
            'shiphero_ready' => true,
            'stale' => false,
            'refresh_pending' => false,
            'message' => '',
            'cached_at' => now()->toIso8601String(),
        ], now()->addMinutes(10));

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('countOrders')->never();
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $this->getJson('/api/orders/queue-counts?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('ready_to_ship', 99);
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
