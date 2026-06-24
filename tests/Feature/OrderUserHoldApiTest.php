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

class OrderUserHoldApiTest extends TestCase
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
            'company_name' => 'User Hold Test Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-user-hold-1',
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function crmUserHoldHolds(): array
    {
        return [
            'fraud_hold' => false,
            'address_hold' => false,
            'shipping_method_hold' => false,
            'operator_hold' => false,
            'payment_hold' => false,
            'client_hold' => true,
        ];
    }

    public function test_set_holds_applies_client_hold_for_user_hold(): void
    {
        $account = $this->makeAccountWithShipHero();
        $account->shiphero_client_refresh_token = 'sh-client-refresh-test';
        $account->save();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('setOrderHoldsTrue')
            ->once()
            ->with('T3JkZXI6MTIz', 'sh-user-hold-1', ['client_hold' => true], 'sh-client-refresh-test');
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $response = $this->postJson('/api/orders/T3JkZXI6MTIz/set-holds', [
            'client_account_id' => $account->id,
            'client_hold' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Holds applied.');
    }

    public function test_set_holds_ignores_operator_hold_flag(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('setOrderHoldsTrue')
            ->once()
            ->with('T3JkZXI6MTIz', 'sh-user-hold-1', ['fraud_hold' => true], null);
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $response = $this->postJson('/api/orders/T3JkZXI6MTIz/set-holds', [
            'client_account_id' => $account->id,
            'fraud_hold' => true,
            'operator_hold' => true,
        ]);

        $response->assertOk();
    }

    public function test_remove_holds_clears_client_hold_when_requested(): void
    {
        $account = $this->makeAccountWithShipHero();
        $account->shiphero_client_refresh_token = 'sh-client-refresh-test';
        $account->save();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $ctx = ['relay_id' => 'T3JkZXI6MTIz', 'holds' => $this->crmUserHoldHolds()];

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('resolveOrderHeaderForMutation')
            ->once()
            ->andReturn($ctx);
        $mock->shouldReceive('clearUserHold')
            ->once()
            ->with('T3JkZXI6MTIz', 'sh-user-hold-1', $ctx, 'sh-client-refresh-test');
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $response = $this->postJson('/api/orders/T3JkZXI6MTIz/remove-holds', [
            'client_account_id' => $account->id,
            'holds_to_clear' => ['client_hold'],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Holds cleared.');
    }

    public function test_remove_holds_without_keys_clears_crm_user_hold(): void
    {
        $account = $this->makeAccountWithShipHero();
        $account->shiphero_client_refresh_token = 'sh-client-refresh-test';
        $account->save();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $ctx = ['relay_id' => 'T3JkZXI6MTIz', 'holds' => $this->crmUserHoldHolds()];

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('resolveOrderHeaderForMutation')
            ->once()
            ->andReturn($ctx);
        $mock->shouldReceive('orderHoldsOnlyUserHoldActive')
            ->once()
            ->andReturn(true);
        $mock->shouldReceive('clearUserHold')
            ->once()
            ->with('T3JkZXI6MTIz', 'sh-user-hold-1', $ctx, 'sh-client-refresh-test');
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $response = $this->postJson('/api/orders/T3JkZXI6MTIz/remove-holds', [
            'client_account_id' => $account->id,
        ]);

        $response->assertOk();
    }
}
