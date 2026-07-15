<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\Permission;
use App\Models\ReturnBill;
use App\Models\User;
use App\Support\Billing\ReturnBillChargeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReturnNonCompliantWorkflowTest extends TestCase
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
        $keys = array_merge(['returns.view', 'clients.view'], $extraPermissionKeys);
        foreach ($keys as $key) {
            $perm = $this->permission($key, explode('.', $key)[0]);
            $user->permissions()->syncWithoutDetaching([$perm->id]);
        }

        return $user;
    }

    private function account(string $suffix = 'nc'): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'NC Return Co '.$suffix,
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-nc-'.$suffix,
        ]);
    }

    private function seedReturnFees(ClientAccount $account, float $nonCompliant = 15.0): void
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
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RETURNS,
            'line_code' => ClientAccountFee::LINE_RETURNS_NON_COMPLIANT,
            'label' => 'Non-Compliant Return',
            'amount' => number_format($nonCompliant, 4, '.', ''),
            'currency' => 'USD',
            'sort_order' => 2,
        ]);
    }

    public function test_create_non_compliant_return_validates_reason_and_assigns_rma(): void
    {
        $account = $this->account();
        $this->seedReturnFees($account);
        Sanctum::actingAs($this->staffUser());

        $this->postJson('/api/admin/returns/non-compliant', [
            'client_account_id' => $account->id,
            'declared_items' => 3,
            'reason' => 'invalid_reason',
        ])->assertUnprocessable();

        $response = $this->postJson('/api/admin/returns/non-compliant', [
            'client_account_id' => $account->id,
            'declared_items' => 3,
            'reason' => 'unable_to_identify_customer',
        ])
            ->assertCreated()
            ->assertJsonPath('status', ClientAccountReturn::STATUS_PENDING)
            ->assertJsonPath('is_non_compliant', true)
            ->assertJsonPath('non_compliant_declared_items', 3)
            ->assertJsonPath('non_compliant_reason', 'unable_to_identify_customer')
            ->assertJsonPath('display_status', 'non_compliant_return')
            ->assertJsonCount(0, 'lines');

        $rma = (string) $response->json('rma_number');
        $this->assertNotSame('', $rma);
        $this->assertDatabaseHas('client_account_returns', [
            'client_account_id' => $account->id,
            'rma_number' => $rma,
            'is_non_compliant' => true,
            'status' => ClientAccountReturn::STATUS_PENDING,
            'created_source' => ClientAccountReturn::SOURCE_ADMIN,
        ]);
    }

    public function test_add_catalog_and_unknown_sku_lines(): void
    {
        $account = $this->account('lines');
        Sanctum::actingAs($this->staffUser());

        $create = $this->postJson('/api/admin/returns/non-compliant', [
            'client_account_id' => $account->id,
            'declared_items' => 2,
            'reason' => 'item_not_sold_by_client',
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

    public function test_pending_list_serializes_non_compliant_display_status(): void
    {
        $account = $this->account('list');
        Sanctum::actingAs($this->staffUser());

        $this->postJson('/api/admin/returns/non-compliant', [
            'client_account_id' => $account->id,
            'declared_items' => 1,
            'reason' => 'mixed_products_multiple_orders',
        ])->assertCreated();

        $this->getJson('/api/admin/returns/pending')
            ->assertOk()
            ->assertJsonPath('data.0.display_status', 'non_compliant_return');
    }

    public function test_fee_defaults_include_non_compliant(): void
    {
        $account = $this->account('fees');
        $this->seedReturnFees($account, 12.5);
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/fee-defaults?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('non_compliant', 12.5)
            ->assertJsonPath('non_compliant_label', 'Non-Compliant Return');
    }

    public function test_process_non_compliant_creates_bill_with_nc_line_and_item_fees(): void
    {
        $account = $this->account('bill');
        $this->seedReturnFees($account, 20.0);
        Sanctum::actingAs($this->staffUser());

        $create = $this->postJson('/api/admin/returns/non-compliant', [
            'client_account_id' => $account->id,
            'declared_items' => 2,
            'reason' => 'unable_to_identify_customer',
        ])->assertCreated();

        $returnId = (int) $create->json('id');

        $lineResponse = $this->postJson('/api/admin/returns/'.$returnId.'/lines', [
            'sku' => 'NC-SKU',
            'name' => 'NC Product',
            'return_qty' => 2,
        ])->assertOk();

        $lineId = (int) $lineResponse->json('lines.0.id');

        $this->postJson('/api/admin/returns/'.$returnId.'/process', [
            'line_ids' => [$lineId],
            'restock_by_line_id' => [$lineId => true],
            'return_bin_number' => 4,
        ])
            ->assertOk()
            ->assertJsonPath('status', ClientAccountReturn::STATUS_RECEIVED)
            ->assertJsonPath('return_fees.non_compliant', 20);

        $return = ClientAccountReturn::query()->findOrFail($returnId);
        $this->assertNotNull($return->return_bill_id);
        $this->assertSame('20.0000', (string) $return->return_fee_non_compliant);

        $bill = ReturnBill::query()->with('items')->findOrFail($return->return_bill_id);
        $ncLine = $bill->items->firstWhere('line_type', ReturnBill::LINE_NON_COMPLIANT);
        $firstLine = $bill->items->firstWhere('line_type', ReturnBill::LINE_FIRST_ITEM);
        $additionalLine = $bill->items->firstWhere('line_type', ReturnBill::LINE_ADDITIONAL_ITEMS);

        $this->assertNotNull($ncLine);
        $this->assertSame(ReturnBillChargeCatalog::NON_COMPLIANT_NAME, $ncLine->name);
        $this->assertNotNull($firstLine);
        $this->assertNotNull($additionalLine);

        $processedLine = ClientAccountReturnLine::query()->findOrFail($lineId);
        $this->assertSame('unable_to_identify_customer', $processedLine->return_reason);
    }

    public function test_line_crud_rejected_for_compliant_pending_return(): void
    {
        $account = $this->account('guard');
        $return = ClientAccountReturn::query()->create([
            'client_account_id' => $account->id,
            'rma_number' => 'ZZ1111',
            'status' => ClientAccountReturn::STATUS_PENDING,
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'shiphero_order_id' => 'order-1',
            'order_number' => '100',
            'items_count' => 1,
        ]);
        $line = ClientAccountReturnLine::query()->create([
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

        $this->patchJson('/api/admin/returns/'.$return->id.'/lines/'.$line->id, [
            'return_qty' => 2,
        ])->assertUnprocessable();
    }
}
