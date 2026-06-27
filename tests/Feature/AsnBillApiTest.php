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

    public function test_add_to_invoice_merges_receiving_line_with_asn_breakdown(): void
    {
        $account = $this->account();
        [$asn, $bill] = $this->asnWithOpenBill($account, 'ASN-9001');
        AsnBillItem::query()->create([
            'asn_bill_id' => $bill->id,
            'line_type' => AsnBill::LINE_RECEIVING_PER_BOX,
            'name' => AsnBillChargeCatalog::displayName(AsnBill::LINE_RECEIVING_PER_BOX),
            'quantity' => 2,
            'unit_price_cents' => 500,
            'line_total_cents' => 1000,
            'sort_order' => 0,
        ]);
        $bill->total_cents = 1000;
        $bill->save();

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

        $this->postJson('/api/asn-bills/'.$bill->id.'/add-to-invoice', [
            'invoice_id' => $invoice->id,
        ])->assertOk();

        $item = InvoiceItem::query()->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($item);
        $this->assertSame(InvoiceLineCategory::RECEIVING, $item->category);
        $breakdown = is_array($item->metadata['breakdown'] ?? null) ? $item->metadata['breakdown'] : [];
        $this->assertCount(1, $breakdown);
        $this->assertSame('ASN-9001', $breakdown[0]['asn_number'] ?? null);
        $this->assertSame('asn_bill', $breakdown[0]['source'] ?? null);

        $bill->refresh();
        $this->assertSame(AsnBill::STATUS_INVOICED, $bill->status);
        $this->assertSame($invoice->id, (int) $bill->invoice_id);
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
