<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\Permission;
use App\Models\ReturnBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReturnThirdPartyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function permission(string $key, string $module): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => $key],
            ['label' => $key, 'module' => $module]
        );
    }

    private function staffUser(array $extraPermissionKeys = []): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $keys = array_merge(['inventory.view', 'clients.view'], $extraPermissionKeys);
        foreach ($keys as $key) {
            $perm = $this->permission($key, explode('.', $key)[0]);
            $user->permissions()->syncWithoutDetaching([$perm->id]);
        }

        return $user;
    }

    private function account(string $suffix = 'tp'): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => '3rd Party Return Co '.$suffix,
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-tp-'.$suffix,
        ]);
    }

    private function seedReturnFees(ClientAccount $account): void
    {
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RETURNS,
            'line_code' => ClientAccountFee::LINE_RETURNS_PROCESSING,
            'label' => 'Return Fee (1st Item)',
            'amount' => '3.0000',
            'currency' => 'USD',
            'sort_order' => 0,
        ]);
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RETURNS,
            'line_code' => ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS,
            'label' => 'Return Fee (Additional Items)',
            'amount' => '1.0000',
            'currency' => 'USD',
            'sort_order' => 1,
        ]);
    }

    public function test_create_third_party_return_validates_channel_and_assigns_rma(): void
    {
        $account = $this->account();
        $this->seedReturnFees($account);
        Sanctum::actingAs($this->staffUser());

        $this->postJson('/api/admin/returns/third-party', [
            'client_account_id' => $account->id,
            'third_party_type' => 'invalid',
        ])->assertUnprocessable();

        $response = $this->postJson('/api/admin/returns/third-party', [
            'client_account_id' => $account->id,
            'third_party_type' => 'amazon',
        ])
            ->assertCreated()
            ->assertJsonPath('status', ClientAccountReturn::STATUS_PENDING)
            ->assertJsonPath('is_third_party', true)
            ->assertJsonPath('return_type', ClientAccountReturn::TYPE_AMAZON)
            ->assertJsonPath('third_party_type', 'amazon')
            ->assertJsonPath('third_party_type_label', 'Amazon')
            ->assertJsonPath('display_status', 'third_party_return')
            ->assertJsonCount(0, 'lines');

        $rma = (string) $response->json('rma_number');
        $this->assertNotSame('', $rma);
        $this->assertDatabaseHas('client_account_returns', [
            'client_account_id' => $account->id,
            'rma_number' => $rma,
            'is_third_party' => true,
            'return_type' => ClientAccountReturn::TYPE_AMAZON,
            'status' => ClientAccountReturn::STATUS_PENDING,
            'created_source' => ClientAccountReturn::SOURCE_ADMIN,
        ]);
    }

    public function test_create_other_third_party_maps_return_type(): void
    {
        $account = $this->account('other');
        Sanctum::actingAs($this->staffUser());

        $this->postJson('/api/admin/returns/third-party', [
            'client_account_id' => $account->id,
            'third_party_type' => 'other',
        ])
            ->assertCreated()
            ->assertJsonPath('return_type', ClientAccountReturn::TYPE_THIRD_PARTY_OTHER)
            ->assertJsonPath('third_party_type', 'other')
            ->assertJsonPath('third_party_type_label', 'Other');
    }

    public function test_add_catalog_and_unknown_sku_lines(): void
    {
        $account = $this->account('lines');
        Sanctum::actingAs($this->staffUser());

        $create = $this->postJson('/api/admin/returns/third-party', [
            'client_account_id' => $account->id,
            'third_party_type' => 'amazon',
        ])->assertCreated();

        $returnId = (int) $create->json('id');

        $this->postJson('/api/admin/returns/'.$returnId.'/lines', [
            'sku' => 'CAT-001',
            'name' => 'Catalog Product',
            'return_qty' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('lines.0.sku', 'CAT-001')
            ->assertJsonPath('lines.0.return_qty', 2)
            ->assertJsonPath('items_count', 2);

        $this->postJson('/api/admin/returns/'.$returnId.'/lines', [
            'sku' => ClientAccountReturn::UNKNOWN_SKU,
            'name' => ClientAccountReturn::UNKNOWN_SKU,
            'return_qty' => 1,
        ])
            ->assertOk()
            ->assertJsonCount(2, 'lines');
    }

    public function test_main_pending_list_excludes_third_party_returns(): void
    {
        $account = $this->account('split');
        Sanctum::actingAs($this->staffUser());

        $this->postJson('/api/admin/returns/third-party', [
            'client_account_id' => $account->id,
            'third_party_type' => 'other',
        ])->assertCreated();

        $this->postJson('/api/admin/returns/non-compliant', [
            'client_account_id' => $account->id,
            'declared_items' => 1,
            'reason' => 'unable_to_identify_customer',
        ])->assertCreated();

        $this->getJson('/api/admin/returns/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_status', 'non_compliant_return');

        $this->getJson('/api/admin/returns/third-party-pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_status', 'third_party_return');
    }

    public function test_process_third_party_creates_bill_with_item_fees_only(): void
    {
        $account = $this->account('bill');
        $this->seedReturnFees($account);
        Sanctum::actingAs($this->staffUser());

        $create = $this->postJson('/api/admin/returns/third-party', [
            'client_account_id' => $account->id,
            'third_party_type' => 'amazon',
        ])->assertCreated();

        $returnId = (int) $create->json('id');

        $lineResponse = $this->postJson('/api/admin/returns/'.$returnId.'/lines', [
            'sku' => 'TP-SKU',
            'name' => 'Third Party Product',
            'return_qty' => 2,
        ])->assertOk();

        $lineId = (int) $lineResponse->json('lines.0.id');

        $this->postJson('/api/admin/returns/'.$returnId.'/process', [
            'line_ids' => [$lineId],
            'restock_by_line_id' => [$lineId => true],
        ])
            ->assertOk()
            ->assertJsonPath('status', ClientAccountReturn::STATUS_RECEIVED)
            ->assertJsonPath('is_third_party', true)
            ->assertJsonPath('third_party_type_label', 'Amazon')
            ->assertJsonMissingPath('return_fees.non_compliant');

        $return = ClientAccountReturn::query()->findOrFail($returnId);
        $this->assertNotNull($return->return_bill_id);
        $this->assertNull($return->return_fee_non_compliant);

        $bill = ReturnBill::query()->with('items')->findOrFail($return->return_bill_id);
        $this->assertNull($bill->items->firstWhere('line_type', ReturnBill::LINE_NON_COMPLIANT));
        $this->assertNotNull($bill->items->firstWhere('line_type', ReturnBill::LINE_FIRST_ITEM));
        $this->assertNotNull($bill->items->firstWhere('line_type', ReturnBill::LINE_ADDITIONAL_ITEMS));

        $processedLine = ClientAccountReturnLine::query()->findOrFail($lineId);
        $this->assertSame('unknown', $processedLine->return_reason);
    }

    public function test_line_crud_rejected_for_compliant_pending_return(): void
    {
        $account = $this->account('guard');
        $return = ClientAccountReturn::query()->create([
            'client_account_id' => $account->id,
            'rma_number' => 'ZZ2222',
            'status' => ClientAccountReturn::STATUS_PENDING,
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'shiphero_order_id' => 'order-1',
            'order_number' => '100',
            'items_count' => 1,
        ]);
        ClientAccountReturnLine::query()->create([
            'client_account_return_id' => $return->id,
            'sku' => 'X',
            'name' => 'X',
            'order_qty' => 1,
            'return_qty' => 1,
            'sort_order' => 0,
        ]);
        Sanctum::actingAs($this->staffUser());

        $this->postJson('/api/admin/returns/'.$return->id.'/lines', [
            'sku' => 'Y',
            'name' => 'Y',
            'return_qty' => 1,
        ])->assertUnprocessable();
    }
}
