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
            ->with('SKU-1', null, null)
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
            ->with('SKU-1', 'WH1', 'LOC1', 7, 'CRM inventory adjustment', null)
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

    public function test_user_can_create_and_list_on_demand_products(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->inventoryViewPermission()->id,
            $this->inventoryUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'On Demand Co',
            'email' => 'on-demand@example.test',
        ]);

        $this->postJson('/api/inventory/on-demand-products', [
            'client_account_id' => $account->id,
            'sku' => ' gso-cbd-gm ',
            'name' => 'CBD Gummies',
            'category' => 'Gummies',
            'price_cents' => 325,
        ])
            ->assertCreated()
            ->assertJsonPath('product.sku', 'GSO-CBD-GM')
            ->assertJsonPath('product.name', 'CBD Gummies')
            ->assertJsonPath('product.category', 'Gummies')
            ->assertJsonPath('product.price_cents', 325);

        $this->getJson('/api/inventory/on-demand-products?q=gso-cbd-gm')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.account_name', 'On Demand Co')
            ->assertJsonPath('products.0.sku', 'GSO-CBD-GM');
    }

    public function test_product_detail_includes_shiphero_legacy_id(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Legacy Co',
            'email' => 'legacy@example.test',
            'shiphero_customer_account_id' => 'sh-legacy-1',
        ]);

        $detail = [
            'id' => 'gid://product/1',
            'shiphero_legacy_id' => 520926306,
            'sku' => 'SKU-LEGACY',
            'name' => 'Legacy Widget',
            'barcode' => '999',
            'image_url' => null,
            'customs_value' => 0,
            'customs_description' => null,
            'dimensions' => ['weight' => 1, 'height' => 1, 'width' => 1, 'length' => 1],
            'storage_cubic_feet' => null,
            'metrics' => ['on_hand' => 0, 'allocated' => 0, 'available' => 0, 'backorder' => 0, 'asn' => 0],
            'kit' => false,
            'kit_build' => false,
            'kit_components' => [],
            'parent_kits' => [],
            'warehouses' => [],
        ];

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('getProductDetailBySku')
            ->once()
            ->with('SKU-LEGACY', null, 'sh-legacy-1', false)
            ->andReturn($detail);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $user = User::factory()->create();
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/inventory/products/SKU-LEGACY?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('product.sku', 'SKU-LEGACY')
            ->assertJsonPath('product.shiphero_legacy_id', 520926306);
    }

    public function test_upload_product_image_requires_file(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Upload Co',
            'email' => 'upload@example.test',
            'shiphero_customer_account_id' => 'sh-upload-1',
        ]);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('updateProductImage')->never();
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->inventoryViewPermission()->id,
            $this->inventoryUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/inventory/products/SKU-1/image', [
            'client_account_id' => $account->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_portal_user_without_inventory_update_can_upload_product_image(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Portal Upload Co',
            'email' => 'portal-upload@example.test',
            'shiphero_customer_account_id' => 'sh-portal-1',
        ]);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('updateProductImage')->never();
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/inventory/products/SKU-PORTAL/image')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_transfer_with_to_location_id_succeeds(): void
    {
        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('transferLocationQuantity')
            ->once()
            ->with('SKU-1', 'WH1', 'LOC-A', 'LOC-B', 4, 'Inventory Reclassification', null)
            ->andReturn([
                'warehouse_id' => 'WH1',
                'warehouse_name' => 'Main',
                'locations' => [
                    [
                        'item_location_id' => 'IL2',
                        'location_id' => 'LOC-B',
                        'location_name' => 'Bin B',
                        'quantity' => 9,
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

        $this->postJson('/api/inventory/transfer', [
            'sku' => 'SKU-1',
            'warehouse_id' => 'WH1',
            'from_location_id' => 'LOC-A',
            'to_location_id' => 'LOC-B',
            'quantity' => 4,
            'reason' => 'Inventory Reclassification',
        ])
            ->assertOk()
            ->assertJsonPath('warehouse.locations.0.quantity', 9);
    }

    public function test_transfer_resolves_location_name_from_product_snapshot(): void
    {
        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('resolveWarehouseLocation')
            ->once()
            ->with('WH1', 'test 3', null)
            ->andReturn(null);
        $mock->shouldReceive('resolveProductWarehouseLocation')
            ->once()
            ->with('SKU-1', 'WH1', 'test 3', null)
            ->andReturn([
                'id' => 'test-3-id',
                'name' => 'test 3',
                'type' => 'Bin (Small)',
                'pickable' => false,
                'sellable' => null,
            ]);
        $mock->shouldReceive('transferLocationQuantity')
            ->once()
            ->with('SKU-1', 'WH1', 'LOC-A', 'test-3-id', 2, 'Restock', null)
            ->andReturn([
                'warehouse_id' => 'WH1',
                'warehouse_name' => 'Main',
                'locations' => [],
            ]);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->inventoryViewPermission()->id,
            $this->inventoryUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/inventory/transfer', [
            'sku' => 'SKU-1',
            'warehouse_id' => 'WH1',
            'from_location_id' => 'LOC-A',
            'to_location' => 'test 3',
            'quantity' => 2,
        ])->assertOk();
    }
}
