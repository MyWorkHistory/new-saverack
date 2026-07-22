<?php

namespace Tests\Feature;

use App\Models\AsnBill;
use App\Models\AsnBillItem;
use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Permission;
use App\Models\User;
use App\Support\Billing\AsnBillChargeCatalog;
use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AsnBillApiTest extends TestCase
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

    private function billingCreatePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.create'],
            ['label' => 'Create billing', 'module' => 'billing']
        );
    }

    private function billingUser(array $extra = []): User
    {
        $perms = [
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
            $this->billingCreatePermission()->id,
        ];
        $user = User::factory()->create();
        $user->permissions()->sync($perms);
        Sanctum::actingAs($user);

        return $user;
    }

    private function account(): ClientAccount
    {
        return ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'ASN Bill Co',
            'email' => 'asn-bill@example.test',
        ]);
    }

    /**
     * @return array{0: ClientAccountAsn, 1: AsnBill}
     */
    private function asnWithOpenBill(ClientAccount $account, string $asnNumber = 'ASN-1001'): array
    {
        $asn = ClientAccountAsn::query()->create([
            'client_account_id' => $account->id,
            'asn_number' => $asnNumber,
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 1,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);

        $bill = AsnBill::query()->create([
            'bill_number' => AsnBill::FIRST_BILL_NUMBER,
            'status' => AsnBill::STATUS_OPEN,
            'client_account_id' => $account->id,
            'client_account_asn_id' => $asn->id,
            'bill_date' => now()->toDateString(),
            'total_cents' => 0,
        ]);
        $asn->asn_bill_id = $bill->id;
        $asn->save();

        return [$asn, $bill];
    }

    public function test_lines_list_excludes_asns_without_fee_lines(): void
    {
        $account = $this->account();
        [, $billWithLine] = $this->asnWithOpenBill($account, 'ASN-A');
        AsnBillItem::query()->create([
            'asn_bill_id' => $billWithLine->id,
            'line_type' => AsnBill::LINE_RECEIVING_PER_BOX,
            'name' => AsnBillChargeCatalog::displayName(AsnBill::LINE_RECEIVING_PER_BOX),
            'quantity' => 2,
            'unit_price_cents' => 500,
            'line_total_cents' => 1000,
            'sort_order' => 0,
        ]);

        $asnEmpty = ClientAccountAsn::query()->create([
            'client_account_id' => $account->id,
            'asn_number' => 'ASN-B',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 1,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        AsnBill::query()->create([
            'bill_number' => AsnBill::FIRST_BILL_NUMBER + 1,
            'status' => AsnBill::STATUS_OPEN,
            'client_account_id' => $account->id,
            'client_account_asn_id' => $asnEmpty->id,
            'bill_date' => now()->toDateString(),
            'total_cents' => 0,
        ]);

        $this->billingUser();

        $res = $this->getJson('/api/asn-bills/lines')->assertOk()->json();
        $this->assertCount(1, $res['data'] ?? []);
        $this->assertSame('ASN-A', $res['data'][0]['asn_number']);
    }

    public function test_index_lists_bills_with_status_and_account(): void
    {
        $account = $this->account();
        [, $billWithLine] = $this->asnWithOpenBill($account, 'ASN-A');
        AsnBillItem::query()->create([
            'asn_bill_id' => $billWithLine->id,
            'line_type' => AsnBill::LINE_RECEIVING_PER_BOX,
            'name' => AsnBillChargeCatalog::displayName(AsnBill::LINE_RECEIVING_PER_BOX),
            'quantity' => 2,
            'unit_price_cents' => 500,
            'line_total_cents' => 1000,
            'sort_order' => 0,
        ]);
        $billWithLine->update(['total_cents' => 1000]);

        $asnEmpty = ClientAccountAsn::query()->create([
            'client_account_id' => $account->id,
            'asn_number' => 'ASN-B',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 1,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        AsnBill::query()->create([
            'bill_number' => AsnBill::FIRST_BILL_NUMBER + 1,
            'status' => AsnBill::STATUS_OPEN,
            'client_account_id' => $account->id,
            'client_account_asn_id' => $asnEmpty->id,
            'bill_date' => now()->toDateString(),
            'total_cents' => 0,
        ]);

        $this->billingUser();

        $res = $this->getJson('/api/asn-bills')->assertOk()->json();
        $this->assertCount(2, $res['data'] ?? []);

        $row = collect($res['data'])->firstWhere('asn_number', 'ASN-A');
        $this->assertNotNull($row);
        $this->assertSame('open', $row['status']);
        $this->assertSame('Open', $row['status_label']);
        $this->assertSame('ASN Bill Co', $row['client_account_name']);
        $this->assertSame(1, $row['items_count']);
        $this->assertSame(1000, $row['total_cents']);
    }

    public function test_crud_lines_on_open_bill(): void
    {
        $account = $this->account();
        [, $bill] = $this->asnWithOpenBill($account);
        $this->billingUser();

        $create = $this->postJson('/api/asn-bills/'.$bill->id.'/items', [
            'line_type' => AsnBill::LINE_RECEIVING_PER_ITEM,
            'name' => AsnBillChargeCatalog::displayName(AsnBill::LINE_RECEIVING_PER_ITEM),
            'quantity' => 3,
            'unit_price' => 1.25,
        ])->assertOk()->json();

        $itemId = (int) ($create['items'][0]['id'] ?? 0);
        $this->assertGreaterThan(0, $itemId);
        $this->assertSame(375, (int) $create['total_cents']);

        $update = $this->putJson('/api/asn-bills/'.$bill->id.'/items/'.$itemId, [
            'line_type' => AsnBill::LINE_RECEIVING_PER_ITEM,
            'name' => AsnBillChargeCatalog::displayName(AsnBill::LINE_RECEIVING_PER_ITEM),
            'quantity' => 4,
            'unit_price' => 1.25,
        ])->assertOk()->json();
        $this->assertSame(500, (int) $update['total_cents']);

        $this->deleteJson('/api/asn-bills/'.$bill->id.'/items/'.$itemId)->assertOk();
        $this->assertSame(0, AsnBillItem::query()->where('asn_bill_id', $bill->id)->count());
    }

    public function test_add_to_invoice_keeps_asn_bills_as_separate_receiving_lines(): void
    {
        $account = $this->account();
        $asnOne = ClientAccountAsn::query()->create([
            'client_account_id' => $account->id,
            'asn_number' => 'ASN-9001',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 1,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        $billOne = AsnBill::query()->create([
            'bill_number' => 9001,
            'status' => AsnBill::STATUS_OPEN,
            'client_account_id' => $account->id,
            'client_account_asn_id' => $asnOne->id,
            'bill_date' => now()->toDateString(),
            'total_cents' => 0,
        ]);
        $asnOne->asn_bill_id = $billOne->id;
        $asnOne->save();

        $asnTwo = ClientAccountAsn::query()->create([
            'client_account_id' => $account->id,
            'asn_number' => 'ASN-9002',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 1,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        $billTwo = AsnBill::query()->create([
            'bill_number' => 9002,
            'status' => AsnBill::STATUS_OPEN,
            'client_account_id' => $account->id,
            'client_account_asn_id' => $asnTwo->id,
            'bill_date' => now()->toDateString(),
            'total_cents' => 0,
        ]);
        $asnTwo->asn_bill_id = $billTwo->id;
        $asnTwo->save();

        AsnBillItem::query()->create([
            'asn_bill_id' => $billOne->id,
            'line_type' => AsnBill::LINE_RECEIVING_PER_BOX,
            'name' => AsnBillChargeCatalog::displayName(AsnBill::LINE_RECEIVING_PER_BOX),
            'quantity' => 2,
            'unit_price_cents' => 500,
            'line_total_cents' => 1000,
            'sort_order' => 0,
        ]);
        $billOne->total_cents = 1000;
        $billOne->save();
        AsnBillItem::query()->create([
            'asn_bill_id' => $billTwo->id,
            'line_type' => AsnBill::LINE_RECEIVING_PER_BOX,
            'name' => AsnBillChargeCatalog::displayName(AsnBill::LINE_RECEIVING_PER_BOX),
            'quantity' => 3,
            'unit_price_cents' => 500,
            'line_total_cents' => 1500,
            'sort_order' => 0,
        ]);
        $billTwo->total_cents = 1500;
        $billTwo->save();

        $this->billingUser();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-ASN-1',
            'client_account_id' => $account->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $this->postJson('/api/asn-bills/'.$billOne->id.'/add-to-invoice', [
            'invoice_id' => $invoice->id,
        ])->assertOk();
        $this->postJson('/api/asn-bills/'.$billTwo->id.'/add-to-invoice', [
            'invoice_id' => $invoice->id,
        ])->assertOk();

        $items = InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->where('category', InvoiceLineCategory::RECEIVING)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $items);
        $this->assertSame(1000, (int) $items[0]->line_total_cents);
        $this->assertSame(1500, (int) $items[1]->line_total_cents);
        $this->assertSame('asn_bill:'.$billOne->id.':'.AsnBill::LINE_RECEIVING_PER_BOX, $items[0]->group_key);
        $this->assertSame('asn_bill:'.$billTwo->id.':'.AsnBill::LINE_RECEIVING_PER_BOX, $items[1]->group_key);
        $this->assertSame('ASN-9001', $items[0]->metadata['asn_number'] ?? null);
        $this->assertSame('ASN-9002', $items[1]->metadata['asn_number'] ?? null);

        $billOne->refresh();
        $billTwo->refresh();
        $this->assertSame(AsnBill::STATUS_INVOICED, $billOne->status);
        $this->assertSame(AsnBill::STATUS_INVOICED, $billTwo->status);
        $this->assertSame($invoice->id, (int) $billOne->invoice_id);
        $this->assertSame($invoice->id, (int) $billTwo->invoice_id);
    }

    public function test_draft_invoices_ensure_creates_draft_when_missing(): void
    {
        $account = $this->account();
        [, $bill] = $this->asnWithOpenBill($account, 'ASN-ENSURE-1');
        AsnBillItem::query()->create([
            'asn_bill_id' => $bill->id,
            'line_type' => AsnBill::LINE_RECEIVING_PER_BOX,
            'name' => AsnBillChargeCatalog::displayName(AsnBill::LINE_RECEIVING_PER_BOX),
            'quantity' => 1,
            'unit_price_cents' => 100,
            'line_total_cents' => 100,
            'sort_order' => 0,
        ]);

        $this->billingUser();

        $this->assertSame(
            0,
            Invoice::query()->where('client_account_id', $account->id)->where('status', Invoice::STATUS_DRAFT)->count()
        );

        $response = $this->getJson('/api/asn-bills/'.$bill->id.'/draft-invoices?ensure=1');
        $response->assertOk();
        $invoices = $response->json('invoices');
        $this->assertIsArray($invoices);
        $this->assertCount(1, $invoices);
        $this->assertSame(
            1,
            Invoice::query()->where('client_account_id', $account->id)->where('status', Invoice::STATUS_DRAFT)->count()
        );
    }

    public function test_asn_bill_item_from_admin_api_appears_on_lines_list(): void
    {
        $account = $this->account();
        $staff = User::factory()->create(['client_account_id' => null]);
        foreach (['inventory.view', 'billing.create', 'billing.update', 'billing.view'] as $key) {
            $perm = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'module' => explode('.', $key)[0]]
            );
            $staff->permissions()->syncWithoutDetaching([$perm->id]);
        }
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::query()->create([
            'client_account_id' => $account->id,
            'asn_number' => 'ASN-ADMIN-1',
            'status' => ClientAccountAsn::STATUS_NON_COMPLIANT,
            'total_boxes' => 1,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);

        $this->postJson('/api/admin/asns/'.$asn->id.'/bill-items', [
            'line_type' => AsnBill::LINE_NON_COMPLIANT,
            'name' => 'Non-Compliant ASN #ASN-ADMIN-1',
            'quantity' => 1,
            'unit_price' => 10,
        ])->assertOk();

        $lines = $this->getJson('/api/asn-bills/lines?search=ASN-ADMIN-1')->assertOk()->json('data');
        $this->assertCount(1, $lines);
        $this->assertSame('ASN-ADMIN-1', $lines[0]['asn_number']);
    }
}
