<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\ClientAccountAsnLine;
use App\Models\Permission;
use App\Models\User;
use App\Services\ShipHeroInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class InventoryProductDetailAsnTest extends TestCase
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

    public function test_product_detail_returns_minimal_payload_for_asn_line_when_shiphero_misses(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Detail ASN Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-detail-1',
        ]);
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0200',
            'status' => ClientAccountAsn::STATUS_DRAFT,
            'total_boxes' => 0,
            'total_pallets' => 0,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        ClientAccountAsnLine::create([
            'client_account_asn_id' => $asn->id,
            'sku' => 'ASN-ONLY-SKU',
            'name' => 'ASN Line Widget',
            'expected_qty' => 3,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
            'sort_order' => 1,
            'shiphero_product_id' => null,
        ]);

        $mock = Mockery::mock(ShipHeroInventoryService::class)->makePartial();
        $mock->shouldReceive('getProductDetailBySku')
            ->andReturn(null);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $this->getJson('/api/inventory/products/ASN-ONLY-SKU?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('product.sku', 'ASN-ONLY-SKU')
            ->assertJsonPath('product.name', 'ASN Line Widget')
            ->assertJsonPath('product.asn_line_only', true)
            ->assertJsonPath('product.metrics.asn', 3);
    }

    public function test_product_detail_asn_metric_sums_expected_qty_from_incomplete_asns(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'ASN Metric Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-asn-metric-1',
        ]);
        $user = User::factory()->create();
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $openAsn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0300',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 0,
            'total_pallets' => 0,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        ClientAccountAsnLine::create([
            'client_account_asn_id' => $openAsn->id,
            'sku' => '45239093395593',
            'name' => 'Green Slab Bag',
            'expected_qty' => 40,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
            'sort_order' => 1,
        ]);

        $inProgressAsn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0301',
            'status' => ClientAccountAsn::STATUS_IN_PROGRESS,
            'total_boxes' => 0,
            'total_pallets' => 0,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        ClientAccountAsnLine::create([
            'client_account_asn_id' => $inProgressAsn->id,
            'sku' => '45239093395593',
            'name' => 'Green Slab Bag',
            'expected_qty' => 15,
            'accepted_qty' => 5,
            'rejected_qty' => 0,
            'sort_order' => 1,
        ]);

        $completedAsn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0302',
            'status' => ClientAccountAsn::STATUS_COMPLETED,
            'total_boxes' => 0,
            'total_pallets' => 0,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        ClientAccountAsnLine::create([
            'client_account_asn_id' => $completedAsn->id,
            'sku' => '45239093395593',
            'name' => 'Green Slab Bag',
            'expected_qty' => 99,
            'accepted_qty' => 99,
            'rejected_qty' => 0,
            'sort_order' => 1,
        ]);

        $otherSkuAsn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0303',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 0,
            'total_pallets' => 0,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        ClientAccountAsnLine::create([
            'client_account_asn_id' => $otherSkuAsn->id,
            'sku' => 'OTHER-SKU',
            'name' => 'Other',
            'expected_qty' => 7,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
            'sort_order' => 1,
        ]);

        $detail = [
            'id' => 'gid://product/asn-metric',
            'sku' => '45239093395593',
            'name' => 'Green Slab Bag',
            'barcode' => null,
            'image_url' => null,
            'customs_value' => 0,
            'customs_description' => null,
            'dimensions' => ['weight' => 0.6, 'height' => 0, 'width' => 0, 'length' => 0],
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
            ->andReturn($detail);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $this->getJson('/api/inventory/products/45239093395593?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('product.metrics.asn', 55);
    }

    public function test_product_detail_matches_asn_line_sku_case_insensitively(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Detail Case Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-detail-2',
        ]);
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0201',
            'status' => ClientAccountAsn::STATUS_DRAFT,
            'total_boxes' => 0,
            'total_pallets' => 0,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        ClientAccountAsnLine::create([
            'client_account_asn_id' => $asn->id,
            'sku' => 'Mixed-Case-Sku',
            'name' => 'Mixed Case Product',
            'expected_qty' => 1,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
            'sort_order' => 1,
            'shiphero_product_id' => 'prod-existing',
        ]);

        $mock = Mockery::mock(ShipHeroInventoryService::class)->makePartial();
        $mock->shouldReceive('getProductDetailBySku')
            ->andReturn(null);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $this->getJson('/api/inventory/products/mixed-case-sku?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('product.sku', 'Mixed-Case-Sku')
            ->assertJsonPath('product.name', 'Mixed Case Product')
            ->assertJsonPath('product.id', 'prod-existing');
    }
}
