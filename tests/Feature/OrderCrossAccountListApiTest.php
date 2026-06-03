<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\CrossAccountOrderListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderCrossAccountListApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_staff_can_list_orders_without_client_account_id(): void
    {
        $this->staffWithOrdersView();

        $this->mock(CrossAccountOrderListService::class, function ($mock): void {
            $mock->shouldReceive('list')
                ->once()
                ->andReturn([
                    'rows' => [
                        [
                            'id' => 'ord-1',
                            'order_number' => '1001',
                            'client_account_id' => 1,
                            'client_account_company_name' => 'Acme',
                        ],
                    ],
                    'pagination' => [
                        'has_next_page' => false,
                        'end_cursor' => null,
                    ],
                    'meta' => [
                        'cross_account' => true,
                        'accounts_queried' => 1,
                    ],
                ]);
        });

        $this->getJson('/api/orders?tab=manage&order_number=1001')
            ->assertOk()
            ->assertJsonPath('meta.cross_account', true)
            ->assertJsonPath('rows.0.order_number', '1001');
    }

    public function test_staff_cross_account_requires_order_number_without_client_account(): void
    {
        $this->staffWithOrdersView();

        $this->getJson('/api/orders?tab=manage')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['order_number']);
    }

    public function test_staff_cannot_paginate_cross_account_list_with_after_cursor(): void
    {
        $this->staffWithOrdersView();

        $this->getJson('/api/orders?tab=manage&after=cursor-1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['after']);
    }

    public function test_portal_user_must_provide_client_account_id(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Portal Client',
            'shiphero_customer_account_id' => 'sh-portal-1',
        ]);

        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->sync([$this->ordersViewPermission()->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/orders?tab=manage')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_account_id']);
    }
}
