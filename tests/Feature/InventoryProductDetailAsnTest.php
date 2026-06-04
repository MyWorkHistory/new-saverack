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
            ->assertJsonPath('product.asn_line_only', true);
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
