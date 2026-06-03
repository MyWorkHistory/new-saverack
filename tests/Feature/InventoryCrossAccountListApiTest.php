<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\CrossAccountInventoryListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryCrossAccountListApiTest extends TestCase
{
    use RefreshDatabase;

    private function inventoryViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'View inventory', 'module' => 'inventory']
        );
    }

    private function staffWithInventoryView(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->sync([$this->inventoryViewPermission()->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_staff_can_list_inventory_without_client_account_id_with_query(): void
    {
        $this->staffWithInventoryView();

        $this->mock(CrossAccountInventoryListService::class, function ($mock): void {
            $mock->shouldReceive('list')
                ->once()
                ->andReturn([
                    'rows' => [
                        [
                            'sku' => 'SKU-1',
                            'name' => 'Widget',
                            'client_account_id' => 1,
                            'client_account_company_name' => 'Acme',
                        ],
                    ],
                    'page_info' => [
                        'has_next_page' => false,
                        'end_cursor' => null,
                    ],
                    'meta' => [
                        'cross_account' => true,
                        'accounts_queried' => 2,
                        'scan_truncated' => false,
                    ],
                ]);
        });

        $this->getJson('/api/inventory/list?query=widget')
            ->assertOk()
            ->assertJsonPath('meta.cross_account', true)
            ->assertJsonPath('rows.0.sku', 'SKU-1');
    }

    public function test_staff_can_browse_inventory_across_accounts_without_query(): void
    {
        $this->staffWithInventoryView();

        $this->mock(CrossAccountInventoryListService::class, function ($mock): void {
            $mock->shouldReceive('list')
                ->once()
                ->andReturn([
                    'rows' => [],
                    'page_info' => [
                        'has_next_page' => false,
                        'end_cursor' => null,
                    ],
                    'meta' => [
                        'cross_account' => true,
                        'accounts_queried' => 5,
                        'scan_truncated' => true,
                    ],
                ]);
        });

        $this->getJson('/api/inventory/list')
            ->assertOk()
            ->assertJsonPath('meta.cross_account', true)
            ->assertJsonPath('meta.scan_truncated', true);
    }

    public function test_staff_cannot_paginate_cross_account_inventory_with_after(): void
    {
        $this->staffWithInventoryView();

        $this->getJson('/api/inventory/list?after=cursor-1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['after']);
    }

    public function test_portal_user_must_provide_client_account_id_for_inventory_list(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Portal Client',
            'shiphero_customer_account_id' => 'sh-portal-1',
        ]);

        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->sync([$this->inventoryViewPermission()->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/inventory/list')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_account_id']);
    }
}
