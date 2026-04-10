<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingInvoiceApiTest extends TestCase
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
            ['label' => 'Create invoices', 'module' => 'billing']
        );
    }

    private function billingUpdatePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.update'],
            ['label' => 'Update invoices', 'module' => 'billing']
        );
    }

    public function test_guest_cannot_access_billing_summary(): void
    {
        $this->getJson('/api/billing/summary')->assertUnauthorized();
    }

    public function test_user_without_billing_view_cannot_access_summary(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/billing/summary')->assertForbidden();
    }

    public function test_user_with_billing_view_can_access_summary(): void
    {
        $user = User::factory()->create();
        $user->permissions()->attach($this->billingViewPermission()->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/billing/summary')
            ->assertOk()
            ->assertJsonStructure([
                'counts_by_status',
                'open_balance_due_cents',
                'overdue_invoice_count',
                'draft_invoice_count',
                'paid_mtd_cents',
            ]);
    }

    public function test_user_with_billing_permissions_can_create_draft_send_and_record_payment(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingCreatePermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Acme Co',
            'email' => 'billing@acme.test',
        ]);

        $create = $this->postJson('/api/invoices', [
            'client_account_id' => $client->id,
            'items' => [
                [
                    'description' => 'Widget',
                    'quantity' => 2,
                    'unit_price_cents' => 1000,
                    'line_total_cents' => 2000,
                ],
            ],
        ]);

        $create->assertCreated();
        $create->assertJsonPath('status', Invoice::STATUS_DRAFT);
        $create->assertJsonPath('total_cents', 2000);
        $invoiceId = $create->json('id');
        $this->assertIsInt($invoiceId);

        $this->postJson("/api/invoices/{$invoiceId}/send")->assertOk();
        $this->assertSame(
            Invoice::STATUS_SENT,
            Invoice::query()->find($invoiceId)->status
        );

        $pay = $this->postJson("/api/invoices/{$invoiceId}/record-payment", [
            'amount_cents' => 2000,
        ]);
        $pay->assertOk();
        $pay->assertJsonPath('status', Invoice::STATUS_PAID);
        $pay->assertJsonPath('balance_due_cents', 0);
    }

    public function test_user_without_billing_view_cannot_list_invoices(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/invoices')->assertForbidden();
    }

    public function test_create_draft_without_line_items(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingCreatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Solo Co',
            'email' => 'solo@acme.test',
        ]);

        $res = $this->postJson('/api/invoices', [
            'client_account_id' => $client->id,
            'due_at' => '2026-05-01',
            'items' => [],
        ]);

        $res->assertCreated();
        $res->assertJsonPath('status', Invoice::STATUS_DRAFT);
        $res->assertJsonPath('total_cents', 0);
        $this->assertNotEmpty($res->json('invoice_number'));
    }

    public function test_create_draft_with_custom_invoice_number(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingCreatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Custom Num Co',
            'email' => 'cn@acme.test',
        ]);

        $res = $this->postJson('/api/invoices', [
            'client_account_id' => $client->id,
            'invoice_number' => 'INV-CUSTOM-001',
            'items' => [],
        ]);

        $res->assertCreated();
        $res->assertJsonPath('invoice_number', 'INV-CUSTOM-001');
    }

    public function test_user_with_billing_view_can_download_invoice_pdf(): void
    {
        $user = User::factory()->create();
        $user->permissions()->attach($this->billingViewPermission()->id);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'PDF Co',
            'email' => 'pdf@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PDF-TEST-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $response = $this->get("/api/invoices/{$invoice->id}/pdf");
        $response->assertOk();
        $ctype = (string) $response->headers->get('content-type');
        $this->assertStringContainsString('application/pdf', $ctype);
        $this->assertNotSame('', $response->getContent());
    }

    public function test_guest_cannot_download_invoice_pdf(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'PDF Co 2',
            'email' => 'pdf2@acme.test',
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PDF-TEST-002',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $this->getJson("/api/invoices/{$invoice->id}/pdf")->assertUnauthorized();
    }
}
