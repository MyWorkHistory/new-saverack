<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\PortalQueueCountsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderQueueCountsApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_queue_counts_returns_immediately_when_cache_cold(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/orders/queue-counts?client_account_id='.$account->id);

        $response->assertOk()
            ->assertJsonPath('refresh_pending', true)
            ->assertJsonPath('ready_to_ship', 0)
            ->assertJsonStructure([
                'awaiting_order_date_from',
                'shipped_order_date_from',
                'cached_at',
            ]);
    }

    public function test_queue_counts_returns_cached_payload_without_blocking(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $service = app(PortalQueueCountsService::class);
        $context = $service->contextForAccount($account);
        Cache::put($context['cache_key'], [
            'ready_to_ship' => 11,
            'on_hold' => 2,
            'backorder' => 1,
            'shipped' => 44,
            'truncated' => true,
            'shiphero_ready' => true,
            'stale' => false,
            'refresh_pending' => false,
            'message' => '',
            'awaiting_order_date_from' => $context['awaiting_from'],
            'awaiting_order_date_to' => $context['awaiting_to'],
            'open_queue_order_date_from' => $context['open_from'],
            'open_queue_order_date_to' => $context['open_to'],
            'shipped_order_date_from' => $context['shipped_from'],
            'shipped_order_date_to' => $context['shipped_to'],
            'cached_at' => now()->toIso8601String(),
        ], now()->addMinutes(10));

        $response = $this->getJson('/api/orders/queue-counts?client_account_id='.$account->id);

        $response->assertOk()
            ->assertJsonPath('ready_to_ship', 11)
            ->assertJsonPath('on_hold', 2)
            ->assertJsonPath('backorder', 1)
            ->assertJsonPath('shipped', 44)
            ->assertJsonPath('truncated', true)
            ->assertJsonPath('refresh_pending', false);

        $payload = $response->json();
        $this->assertSame($payload['open_queue_order_date_from'], $payload['shipped_order_date_from']);
        $this->assertSame($payload['open_queue_order_date_to'], $payload['shipped_order_date_to']);
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
