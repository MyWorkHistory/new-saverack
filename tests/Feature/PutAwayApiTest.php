<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\PutAwaySnapshot;
use App\Models\PutAwaySnapshotRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PutAwayApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_list_requires_client_account_id(): void
    {
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/put-away')
            ->assertStatus(422);
    }

    public function test_list_returns_snapshot_rows_with_search(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $snapshot = PutAwaySnapshot::create([
            'client_account_id' => $account->id,
            'warehouse_id' => 'wh-1',
            'computed_at' => now(),
            'row_count' => 2,
            'status' => PutAwaySnapshot::STATUS_OK,
        ]);

        PutAwaySnapshotRow::create([
            'put_away_snapshot_id' => $snapshot->id,
            'sku' => 'GRPH-US12',
            'name' => 'Water Shoes Graphite 12',
            'barcode' => '810084756300',
            'receiving_qty' => 10,
            'pickable_qty' => 5,
            'non_pickable_qty' => 35,
            'on_hand' => 40,
            'backorder' => 0,
        ]);
        PutAwaySnapshotRow::create([
            'put_away_snapshot_id' => $snapshot->id,
            'sku' => 'OTHER-SKU',
            'name' => 'Other Product',
            'barcode' => '111',
            'receiving_qty' => 0,
            'pickable_qty' => 1,
            'non_pickable_qty' => 0,
            'on_hand' => 1,
            'backorder' => 0,
        ]);

        $this->getJson('/api/admin/put-away?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('rows.0.sku', 'GRPH-US12')
            ->assertJsonPath('rows.0.receiving_qty', 10)
            ->assertJsonCount(2, 'rows');

        $this->getJson('/api/admin/put-away?client_account_id='.$account->id.'&query=810084756300')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.barcode', '810084756300');

        $this->getJson('/api/admin/put-away?client_account_id='.$account->id.'&query=water')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.sku', 'GRPH-US12');
    }

    public function test_list_paginates_snapshot_rows(): void
    {
        $account = $this->account('paginate');
        Sanctum::actingAs($this->staffUser());

        $snapshot = PutAwaySnapshot::create([
            'client_account_id' => $account->id,
            'warehouse_id' => 'wh-1',
            'computed_at' => now(),
            'row_count' => 2,
            'status' => PutAwaySnapshot::STATUS_OK,
        ]);

        foreach (['AAA-SKU', 'BBB-SKU'] as $sku) {
            PutAwaySnapshotRow::create([
                'put_away_snapshot_id' => $snapshot->id,
                'sku' => $sku,
                'name' => $sku,
                'receiving_qty' => 0,
                'pickable_qty' => 0,
                'non_pickable_qty' => 0,
                'on_hand' => 0,
                'backorder' => 0,
            ]);
        }

        $this->getJson('/api/admin/put-away?client_account_id='.$account->id.'&first=1')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('page_info.has_next_page', true)
            ->assertJsonPath('page_info.end_cursor', '1');
    }
}
