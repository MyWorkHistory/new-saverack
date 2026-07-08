<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\ShipHeroOrderQueueIndex;
use App\Models\User;
use App\Services\PortalQueueCountsService;
use App\Services\ShipHeroOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class OrderQueueCountsSnapshotApiTest extends TestCase
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

    public function test_snapshot_returns_index_counts_in_one_request(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Snapshot Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-snapshot-1',
        ]);

        ShipHeroOrderQueueIndex::create([
            'client_account_id' => $account->id,
            'shiphero_order_id' => 'ord-1',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_AWAITING,
            'ready_to_ship' => true,
            'has_backorder' => false,
            'order_date' => now(),
            'list_payload' => ['id' => 'ord-1'],
            'indexed_at' => now(),
            'last_seen_at' => now(),
        ]);

        ShipHeroOrderQueueIndex::create([
            'client_account_id' => $account->id,
            'shiphero_order_id' => 'ord-2',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_SHIPPED,
            'ready_to_ship' => false,
            'has_backorder' => false,
            'ship_date' => now(),
            'list_payload' => ['id' => 'ord-2'],
            'indexed_at' => now(),
            'last_seen_at' => now(),
        ]);

        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('countOrders')->never();
        $mock->shouldReceive('listOrders')->never();
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $this->getJson('/api/orders/queue-counts/snapshot?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('from_index', true)
            ->assertJsonPath('ready_to_ship', 1)
            ->assertJsonPath('shipped', 1);
    }

    public function test_revision_endpoint_returns_cache_revision(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Revision Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-revision-1',
        ]);

        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        app(PortalQueueCountsService::class)->bumpCountsRevision((int) $account->id);

        $this->getJson('/api/orders/queue-counts/revision?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('revision', 1);
    }
}
