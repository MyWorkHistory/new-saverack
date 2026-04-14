<?php

namespace Tests\Feature;

use App\Mail\InvoiceSentMailable;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
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

    private function clientsViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'clients.view'],
            ['label' => 'View client accounts', 'module' => 'clients']
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
        Mail::fake();

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

        Mail::assertSent(InvoiceSentMailable::class, function (InvoiceSentMailable $mail) use ($invoiceId) {
            return $mail->hasTo('chaowang318915@gmail.com')
                && (int) $mail->invoice->id === (int) $invoiceId;
        });

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

    public function test_guest_can_view_public_invoice_html_when_token_valid(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Public Co',
            'email' => 'pub@acme.test',
        ]);
        $client->refresh();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PUB-HTML-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
            'share_token' => 'test-share-token-public-html',
        ]);

        $slug = (string) $client->invoice_share_slug;
        $this->assertNotSame('', $slug);

        $this->get("/billing-invoice/{$slug}/{$invoice->share_token}")
            ->assertOk()
            ->assertSee('Invoice '.$invoice->invoice_number, false);
    }

    public function test_public_invoice_returns_404_for_void_status(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Void Co',
            'email' => 'void@acme.test',
        ]);
        $client->refresh();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PUB-VOID-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_VOID,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
            'share_token' => 'void-token-should-hide',
        ]);

        $slug = (string) $client->invoice_share_slug;

        $this->get("/billing-invoice/{$slug}/{$invoice->share_token}")
            ->assertNotFound();
    }

    public function test_share_link_creates_token_and_returns_customer_urls(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Share Co',
            'email' => 'share@acme.test',
        ]);
        $client->refresh();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-SHARE-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $this->assertNull($invoice->share_token);

        $res = $this->postJson("/api/invoices/{$invoice->id}/share-link");
        $res->assertOk();
        $view = (string) $res->json('customer_view_url');
        $pdf = (string) $res->json('customer_pdf_url');
        $this->assertNotSame('', $view);
        $this->assertStringContainsString('/billing-invoice/', $view);
        $this->assertStringEndsWith('/pdf', $pdf);

        $invoice->refresh();
        $this->assertNotNull($invoice->share_token);
    }

    public function test_draft_line_group_delete_and_detail_includes_customer_urls_when_token_exists(): void
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
            'company_name' => 'Group Co',
            'email' => 'group@acme.test',
        ]);
        $client->refresh();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-GROUP-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 2000,
            'tax_cents' => 0,
            'total_cents' => 2000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 2000,
            'share_token' => 'group-delete-token',
            'share_token_generated_at' => now(),
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'category' => 'postage',
            'group_key' => 'postage:test',
            'description' => 'A',
            'display_name' => 'A',
            'quantity' => 1,
            'unit_price_cents' => 1000,
            'line_total_cents' => 1000,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 2,
            'category' => 'postage',
            'group_key' => 'postage:test',
            'description' => 'B',
            'display_name' => 'B',
            'quantity' => 1,
            'unit_price_cents' => 1000,
            'line_total_cents' => 1000,
        ]);

        $this->deleteJson("/api/invoices/{$invoice->id}/line-groups/postage%3Atest")
            ->assertOk()
            ->assertJsonPath('total_cents', 0);

        $show = $this->getJson("/api/invoices/{$invoice->id}");
        $show->assertOk();
        $this->assertNotNull($show->json('customer_view_url'));
        $this->assertNotNull($show->json('customer_pdf_url'));
    }

    public function test_import_charge_csv_creates_draft_invoice(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingCreatePermission()->id,
            $this->clientsViewPermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Import Co',
            'email' => 'import@acme.test',
        ]);
        $client->refresh();

        $csv = "Charge Name,Charge Type,Qty,Rate,Subtotal\nShip,shipping_label_charge,1,5.00,5.00\n";
        $file = UploadedFile::fake()->createWithContent('charges.csv', $csv);

        $res = $this->post(
            "/api/client-accounts/{$client->id}/invoice-imports/charges",
            [
                'due_at' => '2026-06-15',
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        );

        $res->assertStatus(201);
        $res->assertJsonPath('invoice.status', Invoice::STATUS_DRAFT);
        $this->assertGreaterThanOrEqual(1, count($res->json('invoice.items') ?? []));
    }

    public function test_import_charge_csv_accepts_underscore_headers(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingCreatePermission()->id,
            $this->clientsViewPermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Import Underscore Co',
            'email' => 'iu@acme.test',
        ]);
        $client->refresh();

        $csv = "Charge_Type,Charge_Name,Qty,Rate,Subtotal\nshipping_label_charge,Ship,1,5.00,5.00\n";
        $file = UploadedFile::fake()->createWithContent('charges.csv', $csv);

        $res = $this->post(
            "/api/client-accounts/{$client->id}/invoice-imports/charges",
            [
                'due_at' => '2026-06-15',
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        );

        $res->assertStatus(201);
        $res->assertJsonPath('invoice.status', Invoice::STATUS_DRAFT);
    }
}
