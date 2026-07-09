<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\InventoryProductCrmStatus;
use App\Models\Permission;
use App\Models\ShipHeroInventoryProductIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryCrmStatusApiTest extends TestCase
{
    use RefreshDatabase;

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
            'company_name' => 'CRM Status Test Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-crm-status-1',
        ]);
    }

    private function seedIndexRow(ClientAccount $account, string $sku, bool $productActive = true): ShipHeroInventoryProductIndex
    {
        return ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => $account->shiphero_customer_account_id,
            'shiphero_product_id' => 'prod-'.$sku,
            'sku' => $sku,
            'sku_search' => strtolower($sku),
            'name' => 'Product '.$sku,
            'name_search' => strtolower('Product '.$sku),
            'product_active' => $productActive,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH1',
            'warehouse_active' => true,
            'on_hand' => 5,
            'allocated' => 0,
            'backorder' => 0,
            'synced_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    public function test_default_list_excludes_crm_inactive_skus(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->seedIndexRow($account, 'ACTIVE-SKU');
        $this->seedIndexRow($account, 'INACTIVE-SKU');

        InventoryProductCrmStatus::query()->create([
            'client_account_id' => $account->id,
            'sku' => 'INACTIVE-SKU',
            'crm_active' => false,
        ]);

        $response = $this->getJson(
            '/api/inventory-beta/list?client_account_id='.$account->id.'&first=50&active_status=active'
        );

        $response->assertOk()
            ->assertJsonFragment(['sku' => 'ACTIVE-SKU'])
            ->assertJsonMissing(['sku' => 'INACTIVE-SKU']);
    }

    public function test_inactive_filter_shows_only_crm_inactive_skus(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->seedIndexRow($account, 'ACTIVE-SKU');
        $this->seedIndexRow($account, 'INACTIVE-SKU');

        InventoryProductCrmStatus::query()->create([
            'client_account_id' => $account->id,
            'sku' => 'INACTIVE-SKU',
            'crm_active' => false,
        ]);

        $response = $this->getJson(
            '/api/inventory-beta/list?client_account_id='.$account->id.'&first=50&active_status=inactive'
        );

        $response->assertOk()
            ->assertJsonFragment(['sku' => 'INACTIVE-SKU', 'crm_active' => false])
            ->assertJsonMissing(['sku' => 'ACTIVE-SKU']);
    }

    public function test_search_returns_crm_inactive_sku_when_query_matches(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->seedIndexRow($account, 'HIDDEN-SKU');

        InventoryProductCrmStatus::query()->create([
            'client_account_id' => $account->id,
            'sku' => 'HIDDEN-SKU',
            'crm_active' => false,
        ]);

        $response = $this->getJson(
            '/api/inventory-beta/list?client_account_id='.$account->id.'&first=50&active_status=active&query=hidden'
        );

        $response->assertOk()
            ->assertJsonFragment(['sku' => 'HIDDEN-SKU', 'crm_active' => false]);
    }

    public function test_staff_view_only_cannot_bulk_crm_active(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->patchJson('/api/inventory/products/bulk-crm-active', [
            'client_account_id' => $account->id,
            'active' => false,
            'skus' => ['SKU-1'],
        ])->assertForbidden();
    }

    public function test_portal_user_can_bulk_crm_active_on_own_account_with_view_only(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->seedIndexRow($account, 'PORTAL-SKU', true);

        $this->patchJson('/api/inventory/products/bulk-crm-active', [
            'client_account_id' => $account->id,
            'active' => false,
            'skus' => ['PORTAL-SKU'],
        ])->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('inventory_product_crm_status', [
            'client_account_id' => $account->id,
            'sku' => 'PORTAL-SKU',
            'crm_active' => false,
        ]);
    }

    public function test_portal_user_cannot_bulk_crm_active_on_other_account(): void
    {
        $ownAccount = $this->makeAccountWithShipHero();
        $otherAccount = ClientAccount::create([
            'company_name' => 'Other CRM Status Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-crm-status-other',
        ]);
        $user = User::factory()->create(['client_account_id' => $ownAccount->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->patchJson('/api/inventory/products/bulk-crm-active', [
            'client_account_id' => $otherAccount->id,
            'active' => false,
            'skus' => ['SKU-1'],
        ])->assertForbidden();
    }

    public function test_bulk_crm_active_sets_status_without_changing_shiphero_index_flags(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        $user->permissions()->attach($this->inventoryUpdatePermission()->id);
        Sanctum::actingAs($user);

        $row = $this->seedIndexRow($account, 'BULK-SKU', true);

        $this->patchJson('/api/inventory/products/bulk-crm-active', [
            'client_account_id' => $account->id,
            'active' => false,
            'skus' => ['BULK-SKU'],
        ])->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('inventory_product_crm_status', [
            'client_account_id' => $account->id,
            'sku' => 'BULK-SKU',
            'crm_active' => false,
        ]);

        $row->refresh();
        $this->assertTrue($row->product_active);

        $this->getJson(
            '/api/inventory-beta/list?client_account_id='.$account->id.'&first=50&active_status=active'
        )->assertOk()
            ->assertJsonMissing(['sku' => 'BULK-SKU']);
    }

    public function test_catalog_sync_does_not_remove_crm_status_rows(): void
    {
        $account = $this->makeAccountWithShipHero();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->seedIndexRow($account, 'SYNC-SKU');

        InventoryProductCrmStatus::query()->create([
            'client_account_id' => $account->id,
            'sku' => 'SYNC-SKU',
            'crm_active' => false,
            'updated_by_user_id' => $user->id,
        ]);

        ShipHeroInventoryProductIndex::query()
            ->where('client_account_id', $account->id)
            ->where('sku', 'SYNC-SKU')
            ->update([
                'name' => 'Updated From Sync',
                'product_active' => true,
                'synced_at' => now(),
            ]);

        $this->assertDatabaseHas('inventory_product_crm_status', [
            'client_account_id' => $account->id,
            'sku' => 'SYNC-SKU',
            'crm_active' => false,
        ]);
    }
}
