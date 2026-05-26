<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\PortalQueueCountsService;
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

    public function test_queue_counts_without_queue_returns_cache_only_without_shiphero(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('countOrders')->never();
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $this->getJson('/api/orders/queue-counts?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('refresh_pending', false)
            ->assertJsonPath('ready_to_ship', 0);
    }

    public function test_queue_counts_single_queue_uses_fresh_per_queue_cache(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $service = app(PortalQueueCountsService::class);
        $context = $service->contextForAccount($account);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('queueCacheKey');
        $method->setAccessible(true);
        $cacheKey = $method->invoke($service, $context, 'awaiting');

        Cache::put($cacheKey, [
            'count' => 11,
            'truncated' => false,
            'cached_at' => now()->toIso8601String(),
        ], now()->addMinutes(10));

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('countOrders')->never();
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $this->getJson('/api/orders/queue-counts?client_account_id='.$account->id.'&queue=awaiting')
            ->assertOk()
            ->assertJsonPath('queue', 'awaiting')
            ->assertJsonPath('count', 11)
            ->assertJsonPath('ready_to_ship', 11)
            ->assertJsonPath('refresh_pending', false);
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

        $this->getJson('/api/orders/queue-counts?client_account_id='.$accountB->id.'&queue=awaiting')
            ->assertForbidden();
    }

    public function test_guest_cannot_access_queue_counts(): void
    {
        $account = $this->makeAccountWithShipHero();

        $this->getJson('/api/orders/queue-counts?client_account_id='.$account->id)
            ->assertUnauthorized();
    }
}
