<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\ShipHeroInventoryService;
use App\Services\ShipHeroOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class InventoryProductDetailApiTest extends TestCase
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
            'company_name' => 'Detail Test Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-detail-1',
        ]);
    }

    public function test_product_detail_includes_kit_flags(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('getProductDetailBySku')
            ->once()
            ->with('KIT-SKU', null, 'sh-detail-1')
            ->andReturn([
                'sku' => 'KIT-SKU',
                'name' => 'Kit Product',
                'barcode' => '111222',
                'kit' => true,
                'kit_build' => false,
                'kit_components' => [['sku' => 'PART-1', 'quantity' => 2]],
                'metrics' => ['on_hand' => 1, 'allocated' => 0, 'available' => 1, 'backorder' => 0, 'asn' => 0],
                'dimensions' => ['height' => 14, 'width' => 12, 'length' => 9, 'weight' => 1],
                'warehouses' => [],
            ]);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $this->getJson('/api/inventory/products/KIT-SKU?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('product.kit', true)
            ->assertJsonPath('product.kit_build', false)
            ->assertJsonPath('product.kit_components.0.sku', 'PART-1');
    }

    public function test_allocated_orders_endpoint_returns_rows(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $ordersMock = Mockery::mock(ShipHeroOrderService::class);
        $ordersMock->shouldReceive('listOrdersForProductSku')
            ->once()
            ->with('sh-detail-1', 'SKU-A', 'allocated')
            ->andReturn([
                'rows' => [
                    [
                        'order_id' => 'ord-1',
                        'order_number' => '1001',
                        'order_date' => '2026-05-01T12:00:00Z',
                        'status' => 'unfulfilled',
                        'quantity_allocated' => 3,
                        'backorder_quantity' => 0,
                    ],
                ],
                'truncated' => false,
            ]);
        $this->app->instance(ShipHeroOrderService::class, $ordersMock);

        $this->getJson('/api/inventory/products/SKU-A/allocated-orders?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('rows.0.order_number', '1001')
            ->assertJsonPath('rows.0.quantity_allocated', 3);
    }

    public function test_barcode_label_pdf_requires_barcode(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('getProductDetailBySku')
            ->once()
            ->andReturn([
                'sku' => 'NO-BAR',
                'name' => 'No Barcode',
                'barcode' => null,
                'warehouses' => [],
            ]);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $this->getJson('/api/inventory/products/NO-BAR/barcode-label.pdf?client_account_id='.$account->id)
            ->assertStatus(422);
    }

    public function test_barcode_label_pdf_returns_pdf_when_barcode_present(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('getProductDetailBySku')
            ->once()
            ->andReturn([
                'sku' => 'HAS-BAR',
                'name' => 'With Barcode',
                'barcode' => '9876543210',
                'warehouses' => [],
            ]);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $this->get('/api/inventory/products/HAS-BAR/barcode-label.pdf?client_account_id='.$account->id)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
