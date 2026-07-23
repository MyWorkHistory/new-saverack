<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\InvoiceItem;
use App\Models\ReturnBill;
use App\Models\ReturnBin;
use App\Models\User;
use App\Support\Billing\ReturnBillChargeCatalog;
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

    private function billingDeletePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.delete'],
            ['label' => 'Delete billing', 'module' => 'billing']
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

    private function billingUser(array $extra = []): User
    {
        $perms = [
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ];
        if (in_array('billing.delete', $extra, true)) {
            $perms[] = $this->billingDeletePermission()->id;
        }
        $user = User::factory()->create();
        $user->permissions()->sync($perms);
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

    private function processedReturnWithBill(ClientAccount $account, int $totalUnits = 5, string $rma = 'RB1001'): array
    {
        $this->seedReturnFees($account);
        $staff = $this->staffUser();
        $return = ClientAccountReturn::query()->create([
            'client_account_id' => $account->id,
            'rma_number' => $rma,
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

        Sanctum::actingAs($staff);
        $bin = ReturnBin::query()->create(['name' => 'Bill Bin 2']);
        $this->postJson('/api/admin/returns/'.$return->id.'/process', [
            'line_ids' => [$return->lines()->first()->id],
            'restock_by_line_id' => [$return->lines()->first()->id => false],
            'return_bin_id' => $bin->id,
        ])->assertOk();

        $return->refresh();
        $bill = ReturnBill::query()->findOrFail($return->return_bill_id);

        return [$return, $bill, $staff];
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
        $this->assertSame(ReturnBillChargeCatalog::FIRST_ITEM_NAME, $first->name);
        $this->assertSame(ReturnBillChargeCatalog::ADDITIONAL_ITEMS_NAME, $additional->name);
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
            ->assertJsonPath('data.0.status', ReturnBill::STATUS_OPEN)
            ->assertJsonPath('data.0.items_count', 2);

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

    public function test_detail_includes_created_by_name_and_history_event_labels(): void
    {
        $account = $this->account();
        [, $bill, $staff] = $this->processedReturnWithBill($account, 2);
        $this->billingUser();

        $this->getJson('/api/return-bills/'.$bill->id)
            ->assertOk()
            ->assertJsonPath('created_by_name', $staff->name)
            ->assertJsonPath('histories.0.event_type', 'created')
            ->assertJsonPath('histories.0.event_label', 'Created')
            ->assertJsonPath('items.0.name', ReturnBillChargeCatalog::FIRST_ITEM_NAME);
    }

    public function test_history_includes_invoice_id_on_invoiced(): void
    {
        $account = $this->account();
        [, $bill] = $this->processedReturnWithBill($account, 2);
        $this->billingUser();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-RB-HIST',
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
        ])->assertOk();

        $detail = $this->getJson('/api/return-bills/'.$bill->id)
            ->assertOk()
            ->json();

        $invoicedHistory = collect($detail['histories'] ?? [])
            ->firstWhere('event_type', 'invoiced');

        $this->assertNotNull($invoicedHistory);
        $this->assertSame('Added to Invoice', $invoicedHistory['event_label']);
        $this->assertSame($invoice->id, (int) $invoicedHistory['invoice_id']);
    }

    public function test_delete_open_bill_and_reject_invoiced(): void
    {
        $account = $this->account();
        [, $bill] = $this->processedReturnWithBill($account, 2);
        $this->billingUser(['billing.delete']);

        $returnId = ClientAccountReturn::query()->where('return_bill_id', $bill->id)->value('id');

        $this->deleteJson('/api/return-bills/'.$bill->id)
            ->assertOk();

        $this->assertNull(ReturnBill::query()->find($bill->id));
        $this->assertNull(ClientAccountReturn::query()->find($returnId)->return_bill_id);

        [, $invoicedBill] = $this->processedReturnWithBill($account, 2, 'RB2002');
        $this->billingUser(['billing.delete']);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-RB-DEL',
            'client_account_id' => $account->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);
        $this->postJson('/api/return-bills/'.$invoicedBill->id.'/add-to-invoice', [
            'invoice_id' => $invoice->id,
        ])->assertOk();

        $this->deleteJson('/api/return-bills/'.$invoicedBill->id)
            ->assertForbidden();
    }

    public function test_line_crud_recalculates_total_and_logs_history(): void
    {
        $account = $this->account();
        [, $bill] = $this->processedReturnWithBill($account, 2);
        $this->billingUser();

        $this->postJson('/api/return-bills/'.$bill->id.'/items', [
            'line_type' => ReturnBill::LINE_ASSEMBLY,
            'quantity' => 2,
            'unit_price' => 12.50,
        ])
            ->assertOk()
            ->assertJsonPath('total_cents', 700 + 2500);

        $detail = $this->getJson('/api/return-bills/'.$bill->id)->assertOk()->json();
        $assembly = collect($detail['items'])->firstWhere('line_type', ReturnBill::LINE_ASSEMBLY);
        $this->assertNotNull($assembly);
        $this->assertSame(ReturnBillChargeCatalog::ASSEMBLY_NAME, $assembly['name']);

        $itemId = (int) $assembly['id'];

        $this->putJson('/api/return-bills/'.$bill->id.'/items/'.$itemId, [
            'line_type' => ReturnBill::LINE_ASSEMBLY,
            'quantity' => 1,
            'unit_price' => 20.00,
        ])
            ->assertOk()
            ->assertJsonPath('total_cents', 700 + 2000);

        $this->deleteJson('/api/return-bills/'.$bill->id.'/items/'.$itemId)
            ->assertOk()
            ->assertJsonPath('total_cents', 700);

        $editedHistory = collect($this->getJson('/api/return-bills/'.$bill->id)->json('histories'))
            ->firstWhere('event_type', 'line_add');
        $this->assertNotNull($editedHistory);
        $this->assertSame('Edited', $editedHistory['event_label']);
    }

    public function test_add_two_bills_to_same_draft_merges_invoice_lines_by_display_name(): void
    {
        $account = $this->account();
        [, $billOne] = $this->processedReturnWithBill($account, 5, 'RB3001');
        [, $billTwo] = $this->processedReturnWithBill($account, 5, 'RB3002');
        $this->billingUser();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-RB-MERGE',
            'client_account_id' => $account->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $this->postJson('/api/return-bills/'.$billOne->id.'/add-to-invoice', [
            'invoice_id' => $invoice->id,
        ])->assertOk();

        $this->postJson('/api/return-bills/'.$billTwo->id.'/add-to-invoice', [
            'invoice_id' => $invoice->id,
        ])->assertOk();

        $items = InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $items);

        $first = $items->first(fn ($row) => $row->display_name === ReturnBillChargeCatalog::FIRST_ITEM_NAME);
        $additional = $items->first(fn ($row) => $row->display_name === ReturnBillChargeCatalog::ADDITIONAL_ITEMS_NAME);

        $this->assertNotNull($first);
        $this->assertNotNull($additional);
        $this->assertSame(2.0, (float) $first->quantity);
        $this->assertSame(1000, (int) $first->line_total_cents);
        $this->assertSame(8.0, (float) $additional->quantity);
        $this->assertSame(1600, (int) $additional->line_total_cents);

        $breakdown = is_array($first->metadata['breakdown'] ?? null) ? $first->metadata['breakdown'] : [];
        $this->assertCount(2, $breakdown);
    }

    public function test_charge_options_returns_five_types_with_defaults(): void
    {
        $account = $this->account();
        $this->seedReturnFees($account);
        $this->billingUser();

        $res = $this->getJson('/api/return-bills/charge-options?client_account_id='.$account->id)
            ->assertOk()
            ->json();

        $this->assertCount(5, $res['options'] ?? []);
        $first = collect($res['options'])->firstWhere('line_type', ReturnBill::LINE_FIRST_ITEM);
        $this->assertSame(ReturnBillChargeCatalog::FIRST_ITEM_NAME, $first['display_name']);
        $this->assertSame(500, (int) $first['default_unit_price_cents']);
    }
}
