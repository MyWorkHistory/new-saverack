<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\ShipHeroInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class InventoryListApiTest extends TestCase
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

    private function inventoryUpdatePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'inventory.update'],
            ['label' => 'Update inventory', 'module' => 'inventory']
        );
    }

    private function makeAccountWithShipHero(): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'Inventory List Test Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-inv-list-1',
        ]);
    }

    public function test_list_passes_filters_to_service_and_returns_page_info(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('listInventoryRows')
            ->once()
            ->with('sh-inv-list-1', 50, 'cursor123', 'no', 'inactive')
            ->andReturn([
                'rows' => [
                    [
                        'sku' => 'A',
                        'name' => 'Alpha',
                        'product_id' => 'p1',
                        'image_url' => null,
                        'product_active' => false,
                        'kit' => false,
                        'kit_build' => false,
                        'warehouse_id' => 'w1',
                        'warehouse_active' => true,
                        'on_hand' => 1,
                        'allocated' => 0,
                        'backorder' => 0,
                    ],
                ],
                'page_info' => [
                    'has_next_page' => true,
                    'end_cursor' => 'nextcur',
                ],
            ]);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $response = $this->getJson(
            '/api/inventory/list?client_account_id='.$account->id
            .'&first=50&after=cursor123&kits=no&active_status=inactive'
        );

        $response->assertOk()
            ->assertJsonPath('rows.0.sku', 'A')
            ->assertJsonPath('page_info.has_next_page', true)
            ->assertJsonPath('page_info.end_cursor', 'nextcur');
    }

    public function test_bulk_warehouse_product_active_requires_update_permission(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/inventory/warehouse-products/bulk-active', [
            'client_account_id' => $account->id,
            'active' => true,
            'items' => [
                ['sku' => 'X', 'warehouse_id' => 'W1'],
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_bulk_warehouse_product_active_calls_service(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        $user->permissions()->attach($this->inventoryUpdatePermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('bulkSetWarehouseProductActive')
            ->once()
            ->with('sh-inv-list-1', false, [
                ['sku' => 'SKU1', 'warehouse_id' => 'WH1'],
            ])
            ->andReturn(['updated' => 1, 'errors' => []]);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $response = $this->postJson('/api/inventory/warehouse-products/bulk-active', [
            'client_account_id' => $account->id,
            'active' => false,
            'items' => [
                ['sku' => 'SKU1', 'warehouse_id' => 'WH1'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('errors', []);
    }
}
