<?php

namespace Tests\Feature;

use App\Mail\InvoiceSentMailable;
use App\Models\ClientAccount;
use App\Services\InvoiceService;
use App\Services\StripeInvoicePaymentService;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery;
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

        Mail::assertNothingSent();

        $this->postJson("/api/invoices/{$invoiceId}/email")
            ->assertOk()
            ->assertJsonCount(1, 'recipients');

        Mail::assertSent(InvoiceSentMailable::class, function (InvoiceSentMailable $mail) use ($invoiceId) {
            return $mail->hasTo('billing@acme.test')
                && (int) $mail->invoice->id === (int) $invoiceId;
        });

        $pay = $this->postJson("/api/invoices/{$invoiceId}/record-payment", [
            'amount_cents' => 2000,
        ]);
        $pay->assertOk();
        $pay->assertJsonPath('status', Invoice::STATUS_PAID);
        $pay->assertJsonPath('balance_due_cents', 0);
    }

    public function test_send_email_can_target_selected_recipients(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Recipients Co',
            'email' => 'billing@recipients.test',
        ]);

        User::factory()->create([
            'client_account_id' => $client->id,
            'email' => 'ap@recipients.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-RECIPIENTS-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);

        $res = $this->postJson("/api/invoices/{$invoice->id}/email", [
            'recipients' => ['ap@recipients.test'],
        ]);
        $res->assertOk()->assertJsonCount(1, 'recipients');
        $res->assertJsonPath('recipients.0', 'ap@recipients.test');
    }

    public function test_whatsapp_request_accepts_send_storage_invoice_type(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);
        config()->set('billing.whatsapp.endpoint', 'https://example.com/send');

        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'WA Type Co',
            'email' => 'wa@type.test',
            'whatsapp_e164' => '+15555550123',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-WA-TYPE-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/whatsapp", [
            'type' => 'send_storage_invoice',
        ])->assertOk();
    }

    public function test_pay_context_returns_same_account_invoice_balances(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Context Co',
            'email' => 'context@acme.test',
        ]);

        $current = Invoice::query()->create([
            'invoice_number' => 'INV-CONTEXT-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'due_at' => now()->addDay(),
            'subtotal_cents' => 5000,
            'tax_cents' => 0,
            'total_cents' => 5000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 5000,
        ]);

        Invoice::query()->create([
            'invoice_number' => 'INV-CONTEXT-002',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_PARTIAL,
            'currency' => 'USD',
            'due_at' => now()->subDay(),
            'subtotal_cents' => 3000,
            'tax_cents' => 0,
            'total_cents' => 3000,
            'amount_paid_cents' => 1000,
            'balance_due_cents' => 2000,
        ]);

        Invoice::query()->create([
            'invoice_number' => 'INV-CONTEXT-003',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 1500,
            'tax_cents' => 0,
            'total_cents' => 1500,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1500,
        ]);

        $this->getJson("/api/invoices/{$current->id}/pay-context")
            ->assertOk()
            ->assertJsonPath('account.name', 'Context Co')
            ->assertJsonPath('open_balance_cents', 5000)
            ->assertJsonPath('past_due_balance_cents', 2000)
            ->assertJsonPath('pending_balance_cents', 1500)
            ->assertJsonCount(3, 'rows');
    }

    public function test_pay_allocate_applies_to_draft_invoice_with_balance(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Draft Pay Co',
            'email' => 'draft-pay@acme.test',
        ]);

        $draft = Invoice::query()->create([
            'invoice_number' => 'INV-DRAFT-PAY-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 4000,
            'tax_cents' => 0,
            'total_cents' => 4000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 4000,
        ]);

        $this->postJson("/api/invoices/{$draft->id}/pay-allocate", [
            'amount_cents' => 4000,
            'invoice_ids' => [$draft->id],
            'payment_type' => 'ACH',
            'payment_date' => now()->toDateString(),
        ])->assertOk();

        $draft->refresh();
        $this->assertSame(4000, (int) $draft->amount_paid_cents);
        $this->assertSame(0, (int) $draft->balance_due_cents);
        $this->assertSame(Invoice::STATUS_PAID, $draft->status);
    }

    public function test_pay_allocate_distributes_payment_across_selected_invoices(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Allocate Co',
            'email' => 'allocate@acme.test',
        ]);

        $invoiceA = Invoice::query()->create([
            'invoice_number' => 'INV-ALLOC-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'due_at' => now()->addDay(),
            'subtotal_cents' => 5000,
            'tax_cents' => 0,
            'total_cents' => 5000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 5000,
        ]);

        $invoiceB = Invoice::query()->create([
            'invoice_number' => 'INV-ALLOC-002',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'due_at' => now()->subDay(),
            'subtotal_cents' => 3000,
            'tax_cents' => 0,
            'total_cents' => 3000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 3000,
        ]);

        $res = $this->postJson("/api/invoices/{$invoiceA->id}/pay-allocate", [
            'amount_cents' => 6000,
            'invoice_ids' => [$invoiceB->id, $invoiceA->id],
            'payment_type' => 'ACH',
            'payment_date' => now()->toDateString(),
        ]);

        $res->assertOk()
            ->assertJsonPath('remaining_amount_cents', 0)
            ->assertJsonCount(2, 'allocations');

        $invoiceA->refresh();
        $invoiceB->refresh();

        $this->assertSame(3000, (int) $invoiceA->amount_paid_cents);
        $this->assertSame(2000, (int) $invoiceA->balance_due_cents);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoiceA->status);
        $this->assertSame(3000, (int) $invoiceB->amount_paid_cents);
        $this->assertSame(0, (int) $invoiceB->balance_due_cents);
        $this->assertSame(Invoice::STATUS_PAID, $invoiceB->status);
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
            ->assertSee('Invoice '.$invoice->invoice_number, false)
            ->assertSee('favicon.svg', false);
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

    public function test_import_charge_csv_accepts_category_fee_type_and_unit_rate_aliases(): void
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
            'company_name' => 'Import Alias Co',
            'email' => 'ia@acme.test',
        ]);
        $client->refresh();

        $csv = "Charge Name,Charge Type,Category (fee type),Charge Qty,Unit rate (to charge),Line total (charge)\n"
            . "Label,shipping_label_charge,Fulfillment,1,3.00,3.00\n";
        $file = UploadedFile::fake()->createWithContent('charges.csv', $csv);

        $res = $this->post(
            "/api/client-accounts/{$client->id}/invoice-imports/charges",
            [
                'due_at' => '2026-07-01',
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        );

        $res->assertStatus(201);
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

    public function test_import_charge_csv_skincare_category_maps_to_on_demand_not_fulfillment(): void
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
            'company_name' => 'Skincare Import Co',
            'email' => 'skin@acme.test',
        ]);
        $client->refresh();

        $csv = "Category,Charge Name,Charge Type,Qty,Rate,Subtotal\n"
            ."Skincare,Face Oil Serum,first_pick_charge,2,1.50,3.00\n";
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
        $items = $res->json('invoice.items') ?? [];
        $this->assertCount(1, $items);
        $this->assertSame('on_demand', $items[0]['category']);
        $this->assertStringContainsString('Face Oil Serum', (string) ($items[0]['display_name'] ?? ''));
        $this->assertStringNotContainsStringIgnoringCase('Fulfillment', (string) ($items[0]['display_name'] ?? ''));
    }

    public function test_import_charge_csv_normalizes_packaging_and_inserts_like_old_beta(): void
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
            'company_name' => 'Packaging Import Co',
            'email' => 'pack@acme.test',
        ]);
        $client->refresh();

        $csv = "Charge Name,Charge Type,Qty,Rate,Subtotal\n"
            ."Box BUBBLE MAILER #0 (6 x 10 x 2) used for shipping label 9400150105496091309392,box_charge,2,0.50,1.00\n"
            ."Marketing Insert,order_value_charge,4,0.25,1.00\n";
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
        $items = collect($res->json('invoice.items') ?? []);
        $this->assertTrue($items->contains(fn ($item) => $item['category'] === 'packaging' && $item['display_name'] === 'BUBBLE MAILER #0'));
        $this->assertTrue($items->contains(fn ($item) => $item['category'] === 'packaging' && $item['display_name'] === 'Inserts'));
    }

    public function test_import_charge_csv_flags_basic_box_6x9x1_as_box_not_selected_and_groups(): void
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
            'company_name' => 'Basic Box Import Co',
            'email' => 'bbox@acme.test',
        ]);
        $client->refresh();

        $csv = "Charge Name,Charge Type,Qty,Rate,Subtotal\n"
            ."Basic box (6 x 9 x 1) used for shipping label 940015010549609039198772,box_charge,1,0.25,0.25\n"
            ."Basic box (6 x 9 x 1) used for shipping label 940015010549609039198773,box_charge,1,0.25,0.25\n"
            ."Basic box (6 x 9 x 1) used for shipping label 940015010549609039198774,box_charge,1,0.25,0.25\n";
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
        $items = collect($res->json('invoice.items') ?? [])->values();

        // All Basic Box (6x9x1) collapse into a single grouped line.
        $boxNotSelected = $items->filter(fn ($i) => ($i['category'] ?? null) === 'packaging' && ($i['display_name'] ?? null) === 'Box Not Selected');
        $this->assertCount(1, $boxNotSelected);
        $this->assertSame(3.0, (float) $boxNotSelected->first()['quantity']);
        $this->assertSame('packaging:box-not-selected', $boxNotSelected->first()['group_key']);
        $this->assertTrue((bool) ($boxNotSelected->first()['metadata']['box_not_selected'] ?? false));
    }

    public function test_import_charge_csv_maps_manually_fulfilled_label_to_manual_label_zero_cost(): void
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
            'company_name' => 'Manual Label Import Co',
            'email' => 'ml@acme.test',
        ]);
        $client->refresh();

        $csv = "Charge Name,Charge Type,Qty,Rate,Subtotal\n"
            ."Shipping label for carrier Manually Fulfilled.,shipping_label_charge,1,6.88,6.88\n";
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
        $items = collect($res->json('invoice.items') ?? []);
        $manual = $items->first(fn ($i) => ($i['category'] ?? null) === 'postage' && ($i['display_name'] ?? null) === 'Manual Label');
        $this->assertNotNull($manual);
        $this->assertSame(0, (int) ($manual['unit_price_cents'] ?? -1));
        $this->assertSame(0, (int) ($manual['line_total_cents'] ?? -1));
        $this->assertSame('postage:manual-label', (string) ($manual['group_key'] ?? ''));
    }

    public function test_import_charge_csv_maps_purchase_receiving_category_to_receiving_not_ad_hoc(): void
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
            'company_name' => 'Receiving Import Co',
            'email' => 'recv@acme.test',
        ]);
        $client->refresh();

        // Simulates the “new kind of CSV” receiving rows: billing category indicates purchase receiving.
        $csv = "Charge Name,Charge Type,Category (charge),Charge Qty,Avg Rate,Charge Subtotal\n"
            ."Receiving,receiving_by_item_charge,purchase_c_receiving,1,8.50,8.50\n";
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
        $items = collect($res->json('invoice.items') ?? []);
        $recv = $items->first(fn ($i) => ($i['display_name'] ?? null) === 'Receiving');
        $this->assertNotNull($recv);
        $this->assertSame('receiving', (string) ($recv['category'] ?? ''));
    }

    public function test_invoice_detail_returns_old_beta_presentation_rows(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Presentation Co',
            'email' => 'present@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PRES-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 1100,
            'tax_cents' => 0,
            'total_cents' => 1100,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1100,
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'category' => 'packaging',
            'group_key' => 'packaging:bubble-mailer-0',
            'description' => 'Box BUBBLE MAILER #0 (6 x 10 x 2)',
            'display_name' => 'BUBBLE MAILER #0',
            'quantity' => 2,
            'unit_price_cents' => 50,
            'line_total_cents' => 100,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 2,
            'category' => 'on_demand',
            'group_key' => 'on_demand:face-oil-serum-sku-1',
            'description' => 'Face Oil Serum',
            'display_name' => 'Face Oil Serum (SKU-1)',
            'sku' => 'SKU-1',
            'quantity' => 2,
            'unit_price_cents' => 500,
            'line_total_cents' => 1000,
        ]);

        $res = $this->getJson("/api/invoices/{$invoice->id}");
        $res->assertOk();
        $res->assertJsonPath('presentation.rows.0.name', 'BUBBLE MAILER #0');
        $res->assertJsonPath('presentation.rows.0.type', 'Packaging');
        $res->assertJsonPath('presentation.rows.1.name', 'Face Oil Serum (SKU-1)');
        $res->assertJsonPath('presentation.rows.1.type', 'Product (On-Demand)');
        $this->assertCount(1, $res->json('presentation.rows.0.details'));
        $this->assertCount(1, $res->json('presentation.rows.1.details'));
    }

    public function test_public_invoice_line_sections_match_old_crm_expand_rules(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Expand Rules Co',
            'email' => 'expand@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-EXP-RULES-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 300,
            'tax_cents' => 0,
            'total_cents' => 300,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 300,
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'category' => 'fulfillment',
            'group_key' => 'fulfillment:first-pick',
            'description' => 'First pick',
            'display_name' => 'Fulfillment (First Pick)',
            'quantity' => 1,
            'unit_price_cents' => 100,
            'line_total_cents' => 100,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 2,
            'category' => 'fulfillment',
            'group_key' => 'fulfillment:first-pick',
            'description' => 'Another line',
            'display_name' => 'Fulfillment (First Pick)',
            'quantity' => 1,
            'unit_price_cents' => 100,
            'line_total_cents' => 100,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 3,
            'category' => 'postage',
            'group_key' => 'postage:usps',
            'description' => 'USPS',
            'display_name' => 'USPS Ground',
            'quantity' => 1,
            'unit_price_cents' => 100,
            'line_total_cents' => 100,
        ]);

        $invoice->refresh()->load('items');
        $data = app(InvoiceService::class)->publicInvoiceHtmlData($invoice);
        $sections = $data['line_sections'];
        $fulfillment = collect($sections)->first(fn (array $s) => ($s['type'] ?? '') === 'Fulfillment');
        $postage = collect($sections)->first(fn (array $s) => ($s['type'] ?? '') === 'Postage');

        $this->assertNotNull($fulfillment);
        $this->assertNotEmpty($fulfillment['lines']);
        $this->assertFalse($fulfillment['is_expandable']);

        $this->assertNotNull($postage);
        $this->assertTrue($postage['is_expandable']);
    }

    public function test_invoice_whatsapp_endpoint_sends_provider_payload(): void
    {
        Http::fake([
            'https://wa.example.test/*' => Http::response(['ok' => true], 200),
        ]);
        config()->set('billing.whatsapp.endpoint', 'https://wa.example.test/send');
        config()->set('billing.whatsapp.api_token', 'token-123');

        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'WhatsApp Co',
            'email' => 'wa@acme.test',
            'whatsapp_e164' => '+15555550123',
        ]);
        $client->refresh();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-WA-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
            'billing_period_start' => '2026-04-01',
            'billing_period_end' => '2026-04-30',
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/whatsapp", ['type' => 'invoice_reminder'])
            ->assertOk()
            ->assertJsonPath('whatsapp.to', '+15555550123')
            ->assertJsonPath('whatsapp.type', 'invoice_reminder');

        Http::assertSent(function ($request) use ($invoice) {
            $data = $request->data();
            return $request->url() === 'https://wa.example.test/send'
                && ($data['invoice_id'] ?? null) === $invoice->id
                && ($data['type'] ?? null) === 'invoice_reminder'
                && ! empty($data['url']);
        });
    }

    public function test_replace_line_group_updates_draft_group_items(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Replace Group Co',
            'email' => 'replace@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-GROUP-REPLACE-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'category' => 'packaging',
            'group_key' => 'packaging:bubble',
            'description' => 'BUBBLE MAILER #0',
            'display_name' => 'BUBBLE MAILER #0',
            'quantity' => 1,
            'unit_price_cents' => 1000,
            'line_total_cents' => 1000,
        ]);

        $this->putJson("/api/invoices/{$invoice->id}/line-groups/packaging%3Abubble", [
            'items' => [
                [
                    'description' => 'BUBBLE MAILER #0',
                    'display_name' => 'BUBBLE MAILER #0',
                    'category' => 'packaging',
                    'quantity' => 2,
                    'unit_price_cents' => 500,
                    'line_total_cents' => 1000,
                ],
            ],
        ])->assertOk()->assertJsonPath('presentation.rows.0.line_group_key', 'packaging:bubble');
    }

    public function test_invoice_detail_and_public_payload_include_invoice_date_fields(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Date Co',
            'email' => 'date@acme.test',
        ]);
        $client->refresh();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-DATE-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'issued_at' => '2026-04-16 00:00:00',
            'billing_period_start' => '2026-04-01',
            'billing_period_end' => '2026-04-15',
            'share_token' => 'date-token-001',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $this->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('invoice_date_from', '2026-04-01')
            ->assertJsonPath('invoice_date_to', '2026-04-15')
            ->assertJsonPath('invoice_date_label', '04/01/2026 - 04/15/2026');

        $slug = (string) $client->invoice_share_slug;
        $this->get("/billing-invoice/{$slug}/{$invoice->share_token}")
            ->assertOk()
            ->assertSee('Invoice Date:', false)
            ->assertSee('04/01/2026 - 04/15/2026', false)
            ->assertSee('Save Rack', false);
    }

    public function test_add_to_invoice_and_add_cc_fee_endpoints_update_totals(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Fee Co',
            'email' => 'fee@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-FEE-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/add-item", [
            'description' => 'Manual add',
            'display_name' => 'Manual add',
            'quantity' => 2,
            'unit_price_cents' => 500,
        ])->assertOk()->assertJsonPath('total_cents', 1000);

        $this->postJson("/api/invoices/{$invoice->id}/add-cc-fee", [
            'amount_cents' => 325,
            'label' => 'CC Fee',
        ])->assertOk()->assertJsonPath('total_cents', 1325);
    }

    public function test_draft_breakdown_line_can_be_updated_and_deleted(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Line Edit Co',
            'email' => 'line@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-LINE-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);
        $item = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'category' => 'ad_hoc',
            'group_key' => 'manual:test',
            'description' => 'Test line',
            'display_name' => 'Test line',
            'quantity' => 1,
            'unit_price_cents' => 1000,
            'line_total_cents' => 1000,
        ]);

        $this->putJson("/api/invoices/{$invoice->id}/items/{$item->id}", [
            'description' => 'Edited line',
            'display_name' => 'Edited line',
            'category' => 'ad_hoc',
            'quantity' => 2,
            'unit_price_cents' => 500,
        ])->assertOk()->assertJsonPath('items.0.display_name', 'Edited line');

        $this->deleteJson("/api/invoices/{$invoice->id}/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('total_cents', 0);
    }

    public function test_import_return_label_maps_to_postage_and_preserves_order_number(): void
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
            'company_name' => 'Return Label Co',
            'email' => 'returns@acme.test',
        ]);
        $client->refresh();

        $csv = "Charge Name,Charge Type,Charge Qty,Avg Rate,Charge Subtotal,Order # (shipment)\n"
            ."Endicia return label,shipping_label_charge,1,4.25,4.25,ORD-123\n";
        $file = UploadedFile::fake()->createWithContent('charges.csv', $csv);

        $res = $this->post(
            "/api/client-accounts/{$client->id}/invoice-imports/charges",
            [
                'due_at' => '2026-06-15',
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        );

        $res->assertStatus(201)
            ->assertJsonPath('invoice.items.0.category', 'postage')
            ->assertJsonPath('invoice.items.0.display_name', 'Return Label')
            ->assertJsonPath('invoice.items.0.metadata.order_number', 'ORD-123');

        $invoiceId = $res->json('invoice.id');
        $this->assertIsInt($invoiceId);
        $show = $this->getJson("/api/invoices/{$invoiceId}");
        $show->assertOk()
            ->assertJsonPath('presentation.rows.0.details.0.order_number', 'ORD-123');
    }

    public function test_draft_invoice_can_be_voided_with_update_permission(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Void Draft Co',
            'email' => 'void-draft@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-VOID-DRAFT-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 500,
            'tax_cents' => 0,
            'total_cents' => 500,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 500,
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/void")
            ->assertOk()
            ->assertJsonPath('status', Invoice::STATUS_VOID);
    }

    public function test_group_and_item_edit_delete_allowed_for_sent_non_void_invoice(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Non Draft Edit Co',
            'email' => 'non-draft@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-NON-DRAFT-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);
        $item = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'category' => 'postage',
            'group_key' => 'postage:return-label',
            'description' => 'Return Label',
            'display_name' => 'Return Label',
            'quantity' => 1,
            'unit_price_cents' => 1000,
            'line_total_cents' => 1000,
            'metadata' => ['order_number' => 'ORD-999'],
        ]);

        $this->putJson("/api/invoices/{$invoice->id}/items/{$item->id}", [
            'description' => 'Return Label',
            'display_name' => 'Return Label',
            'category' => 'postage',
            'quantity' => 1,
            'unit_price_cents' => 1000,
            'line_total_cents' => 1000,
            'metadata' => ['order_number' => 'ORD-999'],
        ])->assertOk();

        $this->putJson("/api/invoices/{$invoice->id}/line-groups/postage%3Areturn-label", [
            'items' => [
                [
                    'description' => 'Return Label',
                    'display_name' => 'Return Label',
                    'category' => 'postage',
                    'quantity' => 2,
                    'unit_price_cents' => 500,
                    'line_total_cents' => 1000,
                    'metadata' => ['order_number' => 'ORD-999'],
                ],
            ],
        ])->assertOk();

        $this->deleteJson("/api/invoices/{$invoice->id}/line-groups/postage%3Areturn-label")
            ->assertOk();
    }

    public function test_stripe_payment_methods_requires_customer_id(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'No Stripe Co',
            'email' => 'nostripe@acme.test',
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-STRIPE-NONE-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 2500,
            'tax_cents' => 0,
            'total_cents' => 2500,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 2500,
        ]);

        $this->getJson("/api/invoices/{$invoice->id}/stripe-payment-methods")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stripe Customer ID is missing for this account.');
    }

    public function test_stripe_charge_endpoint_returns_service_result(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Stripe Mock Co',
            'email' => 'stripe-mock@acme.test',
            'stripe_customer_id' => 'cus_test_mock',
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-STRIPE-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 3000,
            'tax_cents' => 0,
            'total_cents' => 3000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 3000,
        ]);

        $mock = Mockery::mock(StripeInvoicePaymentService::class);
        $mock->shouldReceive('chargeInvoice')
            ->once()
            ->andReturn([
                'result' => 'succeeded',
                'status' => 'succeeded',
                'applied_amount_cents' => 3000,
                'payment_intent_id' => 'pi_test_123',
                'invoice' => $invoice->fresh(),
            ]);
        $this->app->instance(StripeInvoicePaymentService::class, $mock);

        $this->postJson("/api/invoices/{$invoice->id}/stripe-charge", [
            'payment_method_id' => 'pm_test_123',
            'amount_cents' => 3000,
        ])->assertOk()
            ->assertJsonPath('result', 'succeeded')
            ->assertJsonPath('payment_intent_id', 'pi_test_123');
    }

    public function test_public_and_pdf_include_updated_payment_address(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([$this->billingViewPermission()->id]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Address Co',
            'email' => 'address@acme.test',
        ]);
        $client->refresh();
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-ADDR-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'share_token' => 'addr-token',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $slug = (string) $client->invoice_share_slug;
        $this->get("/billing-invoice/{$slug}/{$invoice->share_token}")
            ->assertOk()
            ->assertSee('3135 Drane Field Rd #20', false);

        $pdfData = app(InvoiceService::class)->pdfViewData($invoice);
        $html = view('billing.invoice-pdf', $pdfData)->render();
        $this->assertStringContainsString('3135 Drane Field Rd #20', $html);
    }
}
