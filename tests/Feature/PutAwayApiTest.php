<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\PutAwayReceivingSnapshot;
use App\Models\PutAwayReceivingSnapshotRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PutAwayApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shiphero.put_away_warehouse_id' => 'wh-1']);
    }

    private function inventoryViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'View inventory', 'module' => 'inventory']
        );
    }

    private function staffUser(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->syncWithoutDetaching([$this->inventoryViewPermission()->id]);

        return $user;
    }

    private function account(string $suffix = '1'): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'Put Away Co '.$suffix,
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-put-away-'.$suffix,
        ]);
    }

    private function receivingSnapshot(string $warehouseId = 'wh-1'): PutAwayReceivingSnapshot
    {
        return PutAwayReceivingSnapshot::create([
            'warehouse_id' => $warehouseId,
            'computed_at' => now(),
            'row_count' => 2,
            'status' => PutAwayReceivingSnapshot::STATUS_OK,
        ]);
    }

    public function test_list_without_client_account_id_returns_receiving_rows(): void
    {
        $accountA = $this->account('a');
        $accountB = $this->account('b');
        Sanctum::actingAs($this->staffUser());

        $snapshot = $this->receivingSnapshot();

        PutAwayReceivingSnapshotRow::create([
            'put_away_receiving_snapshot_id' => $snapshot->id,
            'client_account_id' => $accountA->id,
            'sku' => 'GRPH-US12',
            'name' => 'Water Shoes Graphite 12',
            'barcode' => '810084756300',
            'receiving_qty' => 10,
            'pickable_qty' => 5,
            'non_pickable_qty' => 35,
            'on_hand' => 40,
            'backorder' => 0,
        ]);
        PutAwayReceivingSnapshotRow::create([
            'put_away_receiving_snapshot_id' => $snapshot->id,
            'client_account_id' => $accountB->id,
            'sku' => 'ZERO-RECV',
            'name' => 'Zero Receiving',
            'barcode' => '222',
            'receiving_qty' => 0,
            'pickable_qty' => 1,
            'non_pickable_qty' => 0,
            'on_hand' => 1,
            'backorder' => 0,
        ]);

        $this->getJson('/api/admin/put-away')
            ->assertOk()
            ->assertJsonPath('rows.0.sku', 'GRPH-US12')
            ->assertJsonPath('rows.0.receiving_qty', 10)
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('meta.source', 'local');
    }

    public function test_list_returns_snapshot_rows_with_search(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $snapshot = $this->receivingSnapshot();

        PutAwayReceivingSnapshotRow::create([
            'put_away_receiving_snapshot_id' => $snapshot->id,
            'client_account_id' => $account->id,
            'sku' => 'GRPH-US12',
            'name' => 'Water Shoes Graphite 12',
            'barcode' => '810084756300',
            'receiving_qty' => 10,
            'pickable_qty' => 5,
            'non_pickable_qty' => 35,
            'on_hand' => 40,
            'backorder' => 0,
        ]);
        PutAwayReceivingSnapshotRow::create([
            'put_away_receiving_snapshot_id' => $snapshot->id,
            'client_account_id' => $account->id,
            'sku' => 'OTHER-SKU',
            'name' => 'Other Product',
            'barcode' => '111',
            'receiving_qty' => 3,
            'pickable_qty' => 1,
            'non_pickable_qty' => 0,
            'on_hand' => 1,
            'backorder' => 0,
        ]);

        $this->getJson('/api/admin/put-away')
            ->assertOk()
            ->assertJsonCount(2, 'rows');

        $this->getJson('/api/admin/put-away?query=810084756300')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.barcode', '810084756300');

        $this->getJson('/api/admin/put-away?query=water')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.sku', 'GRPH-US12');
    }

    public function test_list_filters_by_optional_client_account_id(): void
    {
        $accountA = $this->account('filter-a');
        $accountB = $this->account('filter-b');
        Sanctum::actingAs($this->staffUser());

        $snapshot = $this->receivingSnapshot();

        PutAwayReceivingSnapshotRow::create([
            'put_away_receiving_snapshot_id' => $snapshot->id,
            'client_account_id' => $accountA->id,
            'sku' => 'AAA-SKU',
            'name' => 'Account A Product',
            'receiving_qty' => 4,
            'pickable_qty' => 0,
            'non_pickable_qty' => 0,
            'on_hand' => 4,
            'backorder' => 0,
        ]);
        PutAwayReceivingSnapshotRow::create([
            'put_away_receiving_snapshot_id' => $snapshot->id,
            'client_account_id' => $accountB->id,
            'sku' => 'BBB-SKU',
            'name' => 'Account B Product',
            'receiving_qty' => 6,
            'pickable_qty' => 0,
            'non_pickable_qty' => 0,
            'on_hand' => 6,
            'backorder' => 0,
        ]);

        $this->getJson('/api/admin/put-away?client_account_id='.$accountA->id)
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.sku', 'AAA-SKU')
            ->assertJsonPath('rows.0.client_account_id', $accountA->id);
    }

    public function test_list_paginates_snapshot_rows(): void
    {
        $account = $this->account('paginate');
        Sanctum::actingAs($this->staffUser());

        $snapshot = $this->receivingSnapshot();

        foreach (['AAA-SKU', 'BBB-SKU'] as $sku) {
            PutAwayReceivingSnapshotRow::create([
                'put_away_receiving_snapshot_id' => $snapshot->id,
                'client_account_id' => $account->id,
                'sku' => $sku,
                'name' => $sku,
                'receiving_qty' => 2,
                'pickable_qty' => 0,
                'non_pickable_qty' => 0,
                'on_hand' => 2,
                'backorder' => 0,
            ]);
        }

        $this->getJson('/api/admin/put-away?first=1')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('page_info.has_next_page', true)
            ->assertJsonPath('page_info.end_cursor', '1');
    }

    public function test_list_without_snapshot_returns_empty_local_meta(): void
    {
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/put-away')
            ->assertOk()
            ->assertJsonCount(0, 'rows')
            ->assertJsonPath('meta.stale', false)
            ->assertJsonPath('meta.status', PutAwayReceivingSnapshot::STATUS_OK)
            ->assertJsonPath('meta.source', 'local');
    }

    public function test_refresh_returns_local_meta_without_scanning(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $snapshot = $this->receivingSnapshot();
        PutAwayReceivingSnapshotRow::create([
            'put_away_receiving_snapshot_id' => $snapshot->id,
            'client_account_id' => $account->id,
            'sku' => 'LOCAL-SKU',
            'name' => 'Local SKU',
            'receiving_qty' => 2,
            'pickable_qty' => 0,
            'non_pickable_qty' => 0,
            'on_hand' => 2,
            'backorder' => 0,
        ]);

        $this->postJson('/api/admin/put-away/refresh')
            ->assertOk()
            ->assertJsonPath('status', PutAwayReceivingSnapshot::STATUS_OK)
            ->assertJsonPath('source', 'local')
            ->assertJsonPath('row_count', 1);
    }
}
