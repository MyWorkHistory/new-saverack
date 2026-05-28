<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderSkuLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminHeaderRefreshApiTest extends TestCase
{
    use RefreshDatabase;

    private function ordersViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'orders.view'],
            ['label' => 'View orders', 'module' => 'orders']
        );
    }

    private function clientsViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'clients.view'],
            ['label' => 'View clients', 'module' => 'clients']
        );
    }

    private function staffWithPermissions(array $keys): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $permIds = [];
        foreach ($keys as $key) {
            if ($key === 'orders.view') {
                $permIds[] = $this->ordersViewPermission()->id;
            } elseif ($key === 'clients.view') {
                $permIds[] = $this->clientsViewPermission()->id;
            }
        }
        $user->permissions()->sync($permIds);
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $admin = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );
        $user->roles()->attach($admin->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_crm_lookup_works_without_client_account_id(): void
    {
        $this->staffWithPermissions(['orders.view', 'clients.view']);

        $this->mock(OrderSkuLookupService::class, function ($mock) {
            $mock->shouldReceive('normalizeLookupQuery')
                ->with('ORD-1')
                ->andReturn('ORD-1');
            $mock->shouldReceive('lookupAcrossAccounts')
                ->andReturn([
                    'type' => 'order',
                    'shiphero_order_id' => 'order-xyz',
                    'client_account_id' => 42,
                ]);
        });

        $this->getJson('/api/crm/lookup?query=ORD-1')
            ->assertOk()
            ->assertJsonPath('type', 'order')
            ->assertJsonPath('shiphero_order_id', 'order-xyz')
            ->assertJsonPath('client_account_id', 42);
    }

    public function test_crm_lookup_returns_order_match_for_specific_account(): void
    {
        $this->staffWithPermissions(['orders.view', 'clients.view']);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Lookup Client',
            'shiphero_customer_account_id' => 'sh-lookup-1',
        ]);

        $this->mock(OrderSkuLookupService::class, function ($mock) use ($account) {
            $mock->shouldReceive('normalizeLookupQuery')
                ->with('ORD-100')
                ->andReturn('ORD-100');
            $mock->shouldReceive('resolveShipHeroCustomerAccountId')
                ->andReturn('sh-lookup-1');
            $mock->shouldReceive('lookup')
                ->with($account->id, 'sh-lookup-1', 'ORD-100')
                ->andReturn([
                    'type' => 'order',
                    'shiphero_order_id' => 'order-abc',
                    'client_account_id' => $account->id,
                ]);
        });

        $this->getJson('/api/crm/lookup?query=ORD-100&client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('type', 'order')
            ->assertJsonPath('shiphero_order_id', 'order-abc');
    }

    public function test_admin_cannot_update_own_permissions(): void
    {
        $admin = $this->actingAsAdmin();
        $perm = Permission::query()->firstOrCreate(
            ['key' => 'orders.view'],
            ['label' => 'View orders', 'module' => 'orders']
        );

        $this->putJson('/api/users/'.$admin->id.'/permissions', [
            'permission_keys' => [$perm->key],
        ])->assertForbidden()
            ->assertJsonPath('message', 'You cannot change your own permissions.');
    }

    public function test_staff_can_update_own_profile_without_roles(): void
    {
        $staff = User::factory()->create([
            'client_account_id' => null,
            'name' => 'Before Name',
        ]);
        Sanctum::actingAs($staff);

        $role = Role::query()->firstOrCreate(
            ['name' => 'picker'],
            ['label' => 'Picker', 'description' => 'Picker', 'is_system' => false]
        );

        $this->putJson('/api/users/'.$staff->id, [
            'name' => 'After Name',
            'email' => $staff->email,
            'role_ids' => [$role->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['role_ids']);

        $this->putJson('/api/users/'.$staff->id, [
            'name' => 'After Name',
            'email' => $staff->email,
        ])->assertOk()
            ->assertJsonPath('name', 'After Name');
    }

    public function test_staff_can_view_own_user_record(): void
    {
        $staff = User::factory()->create(['client_account_id' => null]);
        Sanctum::actingAs($staff);

        $this->getJson('/api/users/'.$staff->id)
            ->assertOk()
            ->assertJsonPath('id', $staff->id);
    }
}
