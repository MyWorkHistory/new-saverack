<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AsnApiTest extends TestCase
{
    use RefreshDatabase;

    private function inventoryViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'View inventory', 'module' => 'inventory']
        );
    }

    private function account(): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'ASN Test Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-asn-test-1',
        ]);
    }

    public function test_asn_list_returns_rows_for_portal_user(): void
    {
        $account = $this->account();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => 'ASN-000001',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 2,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);

        $response = $this->getJson('/api/asns?client_account_id='.$account->id);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $asn->id)
            ->assertJsonPath('data.0.asn_number', 'ASN-000001');
    }

    public function test_bulk_delete_rejects_when_any_asn_not_pending(): void
    {
        $account = $this->account();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $pending = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => 'ASN-000010',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 0,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        $open = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => 'ASN-000011',
            'status' => ClientAccountAsn::STATUS_IN_PROGRESS,
            'total_boxes' => 0,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);

        $this->postJson('/api/asns/bulk-delete', [
            'client_account_id' => $account->id,
            'ids' => [$pending->id, $open->id],
        ])->assertStatus(422);

        $this->assertDatabaseHas('client_account_asns', ['id' => $pending->id]);
    }

    public function test_show_forbidden_for_asn_on_other_account(): void
    {
        $accountA = $this->account();
        $accountB = ClientAccount::create([
            'company_name' => 'Other ASN Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-asn-test-2',
        ]);
        $user = User::factory()->create(['client_account_id' => $accountA->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $asnB = ClientAccountAsn::create([
            'client_account_id' => $accountB->id,
            'asn_number' => 'ASN-000099',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 0,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);

        $this->getJson('/api/asns/'.$asnB->id)->assertForbidden();
    }
}
