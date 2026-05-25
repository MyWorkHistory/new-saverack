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
    private function operatorOnlyHolds(): array
    {
        return [
            'fraud_hold' => false,
            'address_hold' => false,
            'shipping_method_hold' => false,
            'operator_hold' => true,
            'payment_hold' => false,
            'client_hold' => false,
        ];
    }

    public function test_set_holds_applies_operator_hold(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('setOrderHoldsTrue')
            ->once()
            ->with('T3JkZXI6MTIz', 'sh-user-hold-1', ['operator_hold' => true]);
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $response = $this->postJson('/api/orders/T3JkZXI6MTIz/set-holds', [
            'client_account_id' => $account->id,
            'operator_hold' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Holds applied.');
    }

    public function test_set_holds_requires_at_least_one_hold_type(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders/T3JkZXI6MTIz/set-holds', [
            'client_account_id' => $account->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_remove_holds_clears_operator_hold_when_requested(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('getOrderHoldsNormalized')
            ->once()
            ->andReturn($this->operatorOnlyHolds());
        $mock->shouldReceive('clearOperatorHold')
            ->once()
            ->with('T3JkZXI6MTIz', 'sh-user-hold-1');
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $response = $this->postJson('/api/orders/T3JkZXI6MTIz/remove-holds', [
            'client_account_id' => $account->id,
            'holds_to_clear' => ['operator_hold'],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Holds cleared.');
    }

    public function test_remove_holds_without_keys_returns_422_when_only_operator_hold(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('getOrderHoldsNormalized')
            ->once()
            ->andReturn($this->operatorOnlyHolds());
        $mock->shouldReceive('orderHoldsOnlyOperatorHoldActive')
            ->once()
            ->andReturn(true);
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $response = $this->postJson('/api/orders/T3JkZXI6MTIz/remove-holds', [
            'client_account_id' => $account->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', ShipHeroOrderService::OPERATOR_HOLD_ONLY_MESSAGE);
    }
}
