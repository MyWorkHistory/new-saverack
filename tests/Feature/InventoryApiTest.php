<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Services\ShipHeroInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class InventoryApiTest extends TestCase
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
            ['label' => 'Update inventory quantities', 'module' => 'inventory']
        );
    }

    public function test_guest_cannot_access_inventory_warehouses(): void
    {
        $this->getJson('/api/inventory/warehouses')->assertUnauthorized();
    }

    public function test_user_without_inventory_view_cannot_list_warehouses(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/inventory/warehouses')->assertForbidden();
    }

    public function test_user_with_inventory_view_can_list_warehouses(): void
    {
        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('listWarehouses')->once()->andReturn([
            ['id' => 'W1', 'legacy_id' => 1, 'identifier' => 'Main', 'label' => 'Main'],
        ]);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $user = User::factory()->create();
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/inventory/warehouses')
            ->assertOk()
            ->assertJsonPath('warehouses.0.id', 'W1')
            ->assertJsonPath('warehouses.0.label', 'Main');
    }

    public function test_search_returns_normalized_payload(): void
    {
        $payload = [
            'id' => 'P1',
            'sku' => 'SKU-1',
            'name' => 'Widget',
            'barcode' => '123456',
            'warehouses' => [
                [
                    'warehouse_id' => 'WH1',
                    'warehouse_name' => 'Main',
                    'locations' => [
                        [
                            'item_location_id' => 'IL1',
                            'location_id' => 'LOC1',
                            'location_name' => 'Bin A',
                            'quantity' => 5,
                        ],
                    ],
                ],
            ],
        ];

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('listWarehouses')->never();
        $mock->shouldReceive('searchProduct')
            ->once()
            ->with('SKU-1', null)
            ->andReturn($payload);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $user = User::factory()->create();
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/inventory/search?q=SKU-1')
            ->assertOk()
            ->assertJsonPath('product.sku', 'SKU-1')
            ->assertJsonPath('product.warehouses.0.locations.0.quantity', 5);
    }

    public function test_user_with_inventory_view_cannot_replace_quantity(): void
    {
        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('replaceLocationQuantity')->never();
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $user = User::factory()->create();
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/inventory/replace', [
            'sku' => 'SKU-1',
            'warehouse_id' => 'WH1',
            'location_id' => 'LOC1',
            'quantity' => 3,
        ])->assertForbidden();
    }

    public function test_replace_calls_service_with_expected_args(): void
    {
        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('replaceLocationQuantity')
            ->once()
            ->with('SKU-1', 'WH1', 'LOC1', 7, 'CRM inventory adjustment')
            ->andReturn([
                'warehouse_id' => 'WH1',
                'warehouse_name' => 'Main',
                'locations' => [
                    [
                        'item_location_id' => 'IL1',
                        'location_id' => 'LOC1',
                        'location_name' => 'Bin A',
                        'quantity' => 7,
                    ],
                ],
            ]);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->inventoryViewPermission()->id,
            $this->inventoryUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/inventory/replace', [
            'sku' => 'SKU-1',
            'warehouse_id' => 'WH1',
            'location_id' => 'LOC1',
            'quantity' => 7,
            'reason' => 'CRM inventory adjustment',
        ])
            ->assertOk()
            ->assertJsonPath('warehouse.locations.0.quantity', 7);
    }
}
