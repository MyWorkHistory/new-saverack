<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\CustomBill;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Permission;
use App\Models\User;
use App\Support\Billing\CustomBillLineType;
use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomBillApiTest extends TestCase
{
    use RefreshDatabase;

    private function billingViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.view'],
            ['label' => 'View billing', 'module' => 'billing']
        );
    }

    private function billingCreatePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.create'],
            ['label' => 'Create billing', 'module' => 'billing']
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

    private function actingWithBilling(array $keys): User
    {
        $user = User::factory()->create();
        $permIds = [];
        foreach ($keys as $key) {
            if ($key === 'billing.view') {
                $permIds[] = $this->billingViewPermission()->id;
            } elseif ($key === 'billing.create') {
                $permIds[] = $this->billingCreatePermission()->id;
            } elseif ($key === 'billing.update') {
                $permIds[] = $this->billingUpdatePermission()->id;
            } elseif ($key === 'billing.delete') {
                $permIds[] = $this->billingDeletePermission()->id;
            }
        }
        $user->permissions()->sync($permIds);
        Sanctum::actingAs($user);

        return $user;
    }

    private function clientAccount(): ClientAccount
    {
        return ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Custom Bill Co',
            'email' => 'cb@example.test',
        ]);
    }

    public function test_guest_cannot_list_custom_bills(): void
    {
        $this->getJson('/api/custom-bills')->assertUnauthorized();
    }

    public function test_user_without_billing_view_cannot_list_custom_bills(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/custom-bills')->assertForbidden();
    }

    public function test_first_bill_number_is_1001(): void
    {
        $user = $this->actingWithBilling(['billing.view', 'billing.create']);
        $client = $this->clientAccount();

        $res = $this->postJson('/api/custom-bills', [
            'client_account_id' => $client->id,
            'bill_date' => now()->toDateString(),
            'items' => [
                [
                    'line_type' => InvoiceLineCategory::AD_HOC,
                    'name' => 'Setup fee',
                    'quantity' => 1,
                    'unit_price' => 25.00,
                ],
            ],
        ]);

        $res->assertCreated()
            ->assertJsonPath('bill_number', 1001)
            ->assertJsonPath('status', CustomBill::STATUS_OPEN)
            ->assertJsonPath('total_cents', 2500)
            ->assertJsonPath('created_by_name', $user->name);

        $billId = (int) $res->json('id');
        $this->getJson("/api/custom-bills/{$billId}")
            ->assertOk()
            ->assertJsonPath('created_by_name', $user->name)
            ->assertJsonPath('histories.0.event_type', 'created')
            ->assertJsonPath('histories.0.event_label', 'Created');
    }

    public function test_list_includes_items_count(): void
    {
        $this->actingWithBilling(['billing.view', 'billing.create']);
        $client = $this->clientAccount();

        $this->postJson('/api/custom-bills', [
            'client_account_id' => $client->id,
            'bill_date' => now()->toDateString(),
            'items' => [
                [
                    'line_type' => InvoiceLineCategory::AD_HOC,
                    'name' => 'Fee',
                    'quantity' => 1,
                    'unit_price' => 10.00,
                ],
            ],
        ])->assertCreated();

        $this->getJson('/api/custom-bills')
            ->assertOk()
            ->assertJsonPath('data.0.items_count', 1);
    }

    public function test_history_includes_event_label_and_invoice_id_on_invoiced(): void
    {
        $this->actingWithBilling(['billing.view', 'billing.create', 'billing.update']);
        $client = $this->clientAccount();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-CB-HIST',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $billRes = $this->postJson('/api/custom-bills', [
            'client_account_id' => $client->id,
            'bill_date' => now()->toDateString(),
            'items' => [
                [
                    'line_type' => InvoiceLineCategory::AD_HOC,
                    'name' => 'Fee',
                    'quantity' => 1,
                    'unit_price' => 5.00,
                ],
            ],
        ])->assertCreated();

        $billId = (int) $billRes->json('id');

        $this->postJson("/api/custom-bills/{$billId}/add-to-invoice", [
            'invoice_id' => $invoice->id,
        ])->assertOk();

        $detail = $this->getJson("/api/custom-bills/{$billId}")
            ->assertOk()
            ->json();

        $invoicedHistory = collect($detail['histories'] ?? [])
            ->firstWhere('event_type', 'invoiced');

        $this->assertNotNull($invoicedHistory);
        $this->assertSame('Added to Invoice', $invoicedHistory['event_label']);
        $this->assertSame($invoice->id, (int) $invoicedHistory['invoice_id']);
    }

    public function test_line_crud_recalculates_total_cents(): void
    {
        $this->actingWithBilling(['billing.view', 'billing.create', 'billing.update']);
        $client = $this->clientAccount();

        $create = $this->postJson('/api/custom-bills', [
            'client_account_id' => $client->id,
            'bill_date' => now()->toDateString(),
            'items' => [],
        ])->assertCreated();

        $billId = (int) $create->json('id');

        $this->postJson("/api/custom-bills/{$billId}/items", [
            'line_type' => InvoiceLineCategory::OTHER,
            'name' => 'Line A',
            'quantity' => 2,
            'unit_price' => 10.00,
        ])->assertOk()->assertJsonPath('total_cents', 2000);

        $itemId = (int) collect($this->getJson("/api/custom-bills/{$billId}")->json('items'))->first()['id'];

        $this->putJson("/api/custom-bills/{$billId}/items/{$itemId}", [
            'line_type' => InvoiceLineCategory::OTHER,
            'name' => 'Line A updated',
            'quantity' => 1,
            'unit_price' => 15.00,
        ])->assertOk()->assertJsonPath('total_cents', 1500);

        $this->deleteJson("/api/custom-bills/{$billId}/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('total_cents', 0);
    }

    public function test_add_to_invoice_appends_items_and_marks_bill_invoiced(): void
    {
        $this->actingWithBilling(['billing.view', 'billing.create', 'billing.update']);
        $client = $this->clientAccount();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-CB-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 500,
            'tax_cents' => 0,
            'total_cents' => 500,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 500,
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Existing',
            'display_name' => 'Existing',
            'category' => 'ad_hoc',
            'quantity' => 1,
            'unit_price_cents' => 500,
            'line_total_cents' => 500,
            'sort_order' => 1,
        ]);

        $billRes = $this->postJson('/api/custom-bills', [
            'client_account_id' => $client->id,
            'bill_date' => now()->toDateString(),
            'items' => [
                [
                    'line_type' => InvoiceLineCategory::POSTAGE,
                    'name' => 'Postage charge',
                    'quantity' => 1,
                    'unit_price' => 12.50,
                ],
            ],
        ])->assertCreated();

        $billId = (int) $billRes->json('id');

        $this->postJson("/api/custom-bills/{$billId}/add-to-invoice", [
            'invoice_id' => $invoice->id,
        ])
            ->assertOk()
            ->assertJsonPath('status', CustomBill::STATUS_INVOICED)
            ->assertJsonPath('invoice_id', $invoice->id);

        $items = InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $items);
        $this->assertSame('Existing', $items[0]->display_name);
        $this->assertSame('Postage charge', $items[1]->display_name);
        $this->assertSame('custom_bill', $items[1]->metadata['source'] ?? null);
        $this->assertSame($billId, (int) ($items[1]->metadata['custom_bill_id'] ?? 0));
    }

    public function test_add_to_invoice_rejects_non_draft_or_wrong_account(): void
    {
        $this->actingWithBilling(['billing.view', 'billing.create', 'billing.update']);
        $clientA = $this->clientAccount();
        $clientB = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Other Co',
            'email' => 'other@example.test',
        ]);

        $billRes = $this->postJson('/api/custom-bills', [
            'client_account_id' => $clientA->id,
            'bill_date' => now()->toDateString(),
            'items' => [
                [
                    'line_type' => InvoiceLineCategory::AD_HOC,
                    'name' => 'Fee',
                    'quantity' => 1,
                    'unit_price' => 5,
                ],
            ],
        ])->assertCreated();

        $billId = (int) $billRes->json('id');

        $sentInvoice = Invoice::query()->create([
            'invoice_number' => 'INV-SENT-001',
            'client_account_id' => $clientA->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $this->postJson("/api/custom-bills/{$billId}/add-to-invoice", [
            'invoice_id' => $sentInvoice->id,
        ])->assertStatus(422);

        $otherDraft = Invoice::query()->create([
            'invoice_number' => 'INV-OTHER-001',
            'client_account_id' => $clientB->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $this->postJson("/api/custom-bills/{$billId}/add-to-invoice", [
            'invoice_id' => $otherDraft->id,
        ])->assertStatus(422);
    }

    public function test_reopen_bill_clears_invoice_id_but_keeps_invoice_items(): void
    {
        $this->actingWithBilling(['billing.view', 'billing.create', 'billing.update']);
        $client = $this->clientAccount();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-REOPEN-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $billRes = $this->postJson('/api/custom-bills', [
            'client_account_id' => $client->id,
            'bill_date' => now()->toDateString(),
            'items' => [
                [
                    'line_type' => InvoiceLineCategory::OTHER,
                    'name' => 'Misc',
                    'quantity' => 1,
                    'unit_price' => 8,
                ],
            ],
        ])->assertCreated();

        $billId = (int) $billRes->json('id');

        $this->postJson("/api/custom-bills/{$billId}/add-to-invoice", [
            'invoice_id' => $invoice->id,
        ])->assertOk();

        $this->assertSame(1, InvoiceItem::query()->where('invoice_id', $invoice->id)->count());

        $this->patchJson("/api/custom-bills/{$billId}/status", [
            'status' => CustomBill::STATUS_OPEN,
        ])
            ->assertOk()
            ->assertJsonPath('status', CustomBill::STATUS_OPEN)
            ->assertJsonPath('invoice_id', null);

        $this->assertSame(1, InvoiceItem::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_user_without_billing_create_cannot_create_bill(): void
    {
        $this->actingWithBilling(['billing.view']);
        $client = $this->clientAccount();

        $this->postJson('/api/custom-bills', [
            'client_account_id' => $client->id,
            'bill_date' => now()->toDateString(),
        ])->assertForbidden();
    }
}
