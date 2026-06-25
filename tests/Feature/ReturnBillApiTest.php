<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\ReturnBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReturnBillApiTest extends TestCase
{
    use RefreshDatabase;

    private function billingViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.view'],
            ['label' => 'View billing', 'module' => 'billing']
        );
    }

    private function billingUpdatePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.update'],
            ['label' => 'Update billing', 'module' => 'billing']
        );
    }

    private function staffUser(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $inventory = Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'inventory.view', 'module' => 'inventory']
        );
        $user->permissions()->syncWithoutDetaching([$inventory->id]);

        return $user;
    }

    private function billingUser(): User
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function account(): ClientAccount
    {
        return ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Return Bill Co',
            'email' => 'rb@example.test',
        ]);
    }

    private function seedReturnFees(ClientAccount $account, string $first = '5.0000', string $additional = '2.0000'): void
    {
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RETURNS,
            'line_code' => ClientAccountFee::LINE_RETURNS_PROCESSING,
            'label' => 'Return Fee (1st Item)',
            'amount' => $first,
            'currency' => 'USD',
            'sort_order' => 0,
        ]);
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RETURNS,
            'line_code' => ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS,
            'label' => 'Return Fee (Additional Items)',
            'amount' => $additional,
            'currency' => 'USD',
            'sort_order' => 1,
        ]);
    }

    private function processedReturnWithBill(ClientAccount $account, int $totalUnits = 5): array
    {
        $this->seedReturnFees($account);
        $return = ClientAccountReturn::query()->create([
            'client_account_id' => $account->id,
            'rma_number' => 'RB1001',
            'status' => ClientAccountReturn::STATUS_PENDING,
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'shiphero_order_id' => 'order-1',
            'order_number' => '10001',
            'customer_name' => 'Customer',
            'items_count' => $totalUnits,
            'return_fee_first_item' => 5.0,
            'return_fee_additional_item' => 2.0,
        ]);
        ClientAccountReturnLine::query()->create([
            'client_account_return_id' => $return->id,
            'sku' => 'SKU-A',
            'name' => 'Item A',
            'order_qty' => $totalUnits,
            'return_qty' => $totalUnits,
            'return_reason' => 'damaged',
            'restock' => true,
            'sort_order' => 0,
        ]);

        Sanctum::actingAs($this->staffUser());
        $this->postJson('/api/admin/returns/'.$return->id.'/process', [
            'line_ids' => [$return->lines()->first()->id],
            'restock_by_line_id' => [$return->lines()->first()->id => false],
        ])->assertOk();

        $return->refresh();
        $bill = ReturnBill::query()->findOrFail($return->return_bill_id);

        return [$return, $bill];
    }

    public function test_process_creates_return_bill_with_correct_quantities_for_five_units(): void
    {
        $account = $this->account();
        [, $bill] = $this->processedReturnWithBill($account, 5);

        $bill->load('items');
        $this->assertSame(ReturnBill::STATUS_OPEN, $bill->status);
        $this->assertCount(2, $bill->items);

        $first = $bill->items->firstWhere('line_type', ReturnBill::LINE_FIRST_ITEM);
        $additional = $bill->items->firstWhere('line_type', ReturnBill::LINE_ADDITIONAL_ITEMS);

        $this->assertNotNull($first);
        $this->assertNotNull($additional);
        $this->assertSame(1.0, (float) $first->quantity);
        $this->assertSame(4.0, (float) $additional->quantity);
        $this->assertSame(500, (int) $first->unit_price_cents);
        $this->assertSame(200, (int) $additional->unit_price_cents);
        $this->assertSame(1300, (int) $bill->total_cents);
    }

    public function test_fee_patch_rejected_after_process(): void
    {
        $account = $this->account();
        [$return] = $this->processedReturnWithBill($account, 2);

        $this->patchJson('/api/admin/returns/'.$return->id.'/fees', [
            'first_item' => 9.99,
        ])->assertStatus(422);
    }

    public function test_return_bill_list_and_add_to_invoice(): void
    {
        $account = $this->account();
        [, $bill] = $this->processedReturnWithBill($account, 3);
        $this->billingUser();

        $this->getJson('/api/return-bills')
            ->assertOk()
            ->assertJsonPath('data.0.id', $bill->id)
            ->assertJsonPath('data.0.status', ReturnBill::STATUS_OPEN);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-RB-001',
            'client_account_id' => $account->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $this->postJson('/api/return-bills/'.$bill->id.'/add-to-invoice', [
            'invoice_id' => $invoice->id,
        ])
            ->assertOk()
            ->assertJsonPath('status', ReturnBill::STATUS_INVOICED)
            ->assertJsonPath('invoice_id', $invoice->id);
    }
}
