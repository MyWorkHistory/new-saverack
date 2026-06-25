<?php

namespace Tests\Feature;

use App\Mail\InvoiceSentMailable;
use App\Models\ClientAccount;
use App\Models\ClientAccountOnDemandProduct;
use App\Services\InvoiceService;
use App\Services\StripeInvoicePaymentService;
use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\InvoiceItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Stripe\PaymentIntent;
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

    private function billingDeletePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.delete'],
            ['label' => 'Delete draft invoices', 'module' => 'billing']
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

    public function test_invoice_list_can_filter_by_client_payment_type(): void
    {
        $user = User::factory()->create();
        $user->permissions()->attach($this->billingViewPermission()->id);
        Sanctum::actingAs($user);

        $cardClient = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Card Client',
            'email' => 'card@example.test',
            'default_payment_type' => 'Credit Card',
        ]);
        $wireClient = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Wire Client',
            'email' => 'wire@example.test',
            'default_payment_type' => 'Wire',
        ]);

        Invoice::query()->create([
            'invoice_number' => 'INV-CARD-001',
            'client_account_id' => $cardClient->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);
        Invoice::query()->create([
            'invoice_number' => 'INV-WIRE-001',
            'client_account_id' => $wireClient->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 2000,
            'tax_cents' => 0,
            'total_cents' => 2000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 2000,
        ]);

        $this->getJson('/api/invoices?payment_type=Credit%20Card')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoice_number', 'INV-CARD-001')
            ->assertJsonPath('data.0.client_account_default_payment_type', 'Credit Card');
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
                && $mail->hasFrom('billing@saverack.com', 'Save Rack Billing')
                && (int) $mail->invoice->id === (int) $invoiceId;
        });

        $pay = $this->postJson("/api/invoices/{$invoiceId}/record-payment", [
            'amount_cents' => 2000,
        ]);
        $pay->assertOk();
        $pay->assertJsonPath('status', Invoice::STATUS_PAID);
        $pay->assertJsonPath('balance_due_cents', 0);
    }

    public function test_send_email_is_limited_to_account_email(): void
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
            'email' => 'billing@example.com',
        ]);

        User::factory()->create([
            'client_account_id' => $client->id,
            'email' => 'ap@example.com',
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

        $this->postJson("/api/invoices/{$invoice->id}/email", [
            'recipients' => ['ap@example.com'],
        ])->assertStatus(422);

        $res = $this->postJson("/api/invoices/{$invoice->id}/email");
        $res->assertOk()->assertJsonCount(1, 'recipients');
        $res->assertJsonPath('recipients.0', 'billing@example.com');
    }

    public function test_whatsapp_request_accepts_send_storage_invoice_type(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);
        config()->set('billing.whatsapp.endpoint', 'https://example.com/send');
        config()->set('billing.whatsapp.api_token', 'token-123');
        config()->set('services.whatsapp.phone', '17272554885');

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
            'whatsapp_api_id' => 'wa-chat-15555550123',
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

    public function test_pay_context_returns_available_funds_for_zero_balance_draft(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Available Funds Co',
            'email' => 'available-funds@acme.test',
            'billing_available_funds_cents' => 206793,
        ]);

        $draft = Invoice::query()->create([
            'invoice_number' => 'INV-AVAILABLE-FUNDS-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $this->getJson("/api/invoices/{$draft->id}/pay-context")
            ->assertOk()
            ->assertJsonPath('available_funds_cents', 206793);
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

    public function test_add_available_funds_increments_account_pool_and_survives_cancel_flow(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Funds Pool Co',
            'email' => 'funds-pool@acme.test',
            'billing_available_funds_cents' => 2500,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-FUNDS-001',
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

        $this->postJson("/api/invoices/{$invoice->id}/add-available-funds", [
            'amount_cents' => 1500,
            'payment_type' => 'ACH',
            'payment_date' => now()->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('available_funds_cents', 4000);

        $client->refresh();
        $this->assertSame(4000, (int) $client->billing_available_funds_cents);

        $this->getJson("/api/invoices/{$invoice->id}/pay-context")
            ->assertOk()
            ->assertJsonPath('available_funds_cents', 4000);

        $this->assertDatabaseHas('invoice_histories', [
            'invoice_id' => $invoice->id,
            'action' => 'funds_added',
        ]);
        $history = InvoiceHistory::query()
            ->where('invoice_id', $invoice->id)
            ->where('action', 'funds_added')
            ->latest('id')
            ->first();
        $this->assertNotNull($history);
        $this->assertSame(1500, (int) ($history->meta['amount_cents'] ?? 0));
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

        $allocatedHistory = InvoiceHistory::query()
            ->where('invoice_id', $invoiceA->id)
            ->where('action', 'payment_allocated')
            ->latest('id')
            ->first();
        $this->assertNotNull($allocatedHistory);
        $this->assertSame(6000, (int) ($allocatedHistory->meta['total_applied_cents'] ?? 0));
        $this->assertCount(2, $allocatedHistory->meta['allocations'] ?? []);

        $appliedB = InvoiceHistory::query()
            ->where('invoice_id', $invoiceB->id)
            ->where('action', 'payment_applied')
            ->latest('id')
            ->first();
        $this->assertNotNull($appliedB);
        $this->assertSame(3000, (int) ($appliedB->meta['amount_cents'] ?? 0));

        $appliedA = InvoiceHistory::query()
            ->where('invoice_id', $invoiceA->id)
            ->where('action', 'payment_applied')
            ->latest('id')
            ->first();
        $this->assertNotNull($appliedA);
        $this->assertSame(3000, (int) ($appliedA->meta['amount_cents'] ?? 0));
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

    public function test_create_draft_without_due_at_uses_account_payment_terms(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 12:00:00'));

        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingCreatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Terms Co',
            'email' => 'terms@acme.test',
            'payment_terms_days' => 5,
        ]);

        $res = $this->postJson('/api/invoices', [
            'client_account_id' => $client->id,
            'items' => [],
        ]);

        $res->assertCreated();
        $this->assertStringStartsWith('2026-06-07', (string) $res->json('due_at'));

        Carbon::setTestNow();
    }

    public function test_import_charge_csv_without_due_at_uses_account_payment_terms(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 12:00:00'));

        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingCreatePermission()->id,
            $this->clientsViewPermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Import Terms Co',
            'email' => 'import-terms@acme.test',
            'payment_terms_days' => 5,
        ]);

        $csv = "Charge Name,Charge Type,Qty,Rate,Subtotal\nShip,shipping_label_charge,1,5.00,5.00\n";
        $file = UploadedFile::fake()->createWithContent('charges.csv', $csv);

        $res = $this->post(
            "/api/client-accounts/{$client->id}/invoice-imports/charges",
            [
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        );

        $res->assertStatus(201);
        $this->assertStringStartsWith('2026-06-07', (string) $res->json('invoice.due_at'));

        Carbon::setTestNow();
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

    public function test_public_invoice_html_shows_pay_now_and_updated_copy(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Public Copy Co',
            'email' => 'public-copy@acme.test',
        ]);
        $client->refresh();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PUB-COPY-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 4994,
            'tax_cents' => 0,
            'total_cents' => 4994,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 4994,
            'share_token' => 'public-copy-token',
        ]);

        $slug = (string) $client->invoice_share_slug;
        $this->get("/billing-invoice/{$slug}/{$invoice->share_token}")
            ->assertOk()
            ->assertSee('Pay Now', false)
            ->assertDontSee('javascript:window.print()', false)
            ->assertSee('Invoice Amount', false)
            ->assertSee('For a detailed breakdown of charges associated with each order, please log in to your account.', false);
    }

    public function test_public_invoice_html_renders_nested_category_service_order_breakdown(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Nested Public Co',
            'email' => 'nested-public@acme.test',
        ]);
        $client->refresh();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PUB-NESTED-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
            'share_token' => 'nested-public-token',
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'category' => 'postage',
            'group_key' => 'postage:endicia',
            'description' => 'Postage',
            'display_name' => 'Endicia (USPS)',
            'quantity' => 1,
            'unit_price_cents' => 629,
            'line_total_cents' => 629,
            'metadata' => ['order_number' => 'A1001'],
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 2,
            'category' => 'postage',
            'group_key' => 'postage:endicia',
            'description' => 'Postage',
            'display_name' => 'Endicia (USPS)',
            'quantity' => 1,
            'unit_price_cents' => 609,
            'line_total_cents' => 609,
            'metadata' => [],
        ]);

        app(InvoiceService::class)->recalculateTotals($invoice);
        $invoice->save();

        $slug = (string) $client->invoice_share_slug;
        $this->get("/billing-invoice/{$slug}/{$invoice->share_token}")
            ->assertOk()
            ->assertSee('Postage', false)
            ->assertSee('Endicia (USPS)', false)
            ->assertSee('Order #A1001', false)
            ->assertSee('Order #—', false);
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

    public function test_public_pay_route_redirects_to_checkout_url_when_service_returns_url(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Public Pay Co',
            'email' => 'public-pay@acme.test',
        ]);
        $client->refresh();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PUB-PAY-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1500,
            'tax_cents' => 0,
            'total_cents' => 1500,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1500,
            'share_token' => 'public-pay-token',
        ]);

        $mock = Mockery::mock(StripeInvoicePaymentService::class);
        $mock->shouldReceive('createPublicCheckoutUrl')
            ->once()
            ->andReturn('https://checkout.stripe.test/session/abc123');
        $this->app->instance(StripeInvoicePaymentService::class, $mock);

        $slug = (string) $client->invoice_share_slug;
        $this->get("/billing-invoice/{$slug}/{$invoice->share_token}/pay")
            ->assertRedirect('https://checkout.stripe.test/session/abc123');
    }

    public function test_public_pay_route_passes_checkout_session_placeholder_in_success_url(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Public Pay Placeholder Co',
            'email' => 'public-pay-placeholder@acme.test',
        ]);
        $client->refresh();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PUB-PAY-PLACEHOLDER-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1500,
            'tax_cents' => 0,
            'total_cents' => 1500,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1500,
            'share_token' => 'public-pay-placeholder-token',
        ]);

        $mock = Mockery::mock(StripeInvoicePaymentService::class);
        $mock->shouldReceive('createPublicCheckoutUrl')
            ->once()
            ->withArgs(function ($inv, $successUrl, $cancelUrl) use ($invoice): bool {
                return (int) $inv->id === (int) $invoice->id
                    && str_contains((string) $successUrl, 'payment=success')
                    && str_contains((string) $successUrl, 'session_id={CHECKOUT_SESSION_ID}')
                    && str_contains((string) $cancelUrl, 'payment=cancel');
            })
            ->andReturn('https://checkout.stripe.test/session/placeholder123');
        $this->app->instance(StripeInvoicePaymentService::class, $mock);

        $slug = (string) $client->invoice_share_slug;
        $this->get("/billing-invoice/{$slug}/{$invoice->share_token}/pay")
            ->assertRedirect('https://checkout.stripe.test/session/placeholder123');
    }

    public function test_public_pay_route_redirects_back_with_error_when_checkout_fails(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Public Pay Error Co',
            'email' => 'public-pay-error@acme.test',
        ]);
        $client->refresh();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PUB-PAY-ERR-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1500,
            'tax_cents' => 0,
            'total_cents' => 1500,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1500,
            'share_token' => 'public-pay-error-token',
        ]);

        $mock = Mockery::mock(StripeInvoicePaymentService::class);
        $mock->shouldReceive('createPublicCheckoutUrl')
            ->once()
            ->andThrow(new \RuntimeException('Stripe unavailable'));
        $this->app->instance(StripeInvoicePaymentService::class, $mock);

        $slug = (string) $client->invoice_share_slug;
        $this->get("/billing-invoice/{$slug}/{$invoice->share_token}/pay")
            ->assertRedirect("/billing-invoice/{$slug}/{$invoice->share_token}?payment=error");
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

    public function test_line_group_delete_scoped_by_item_ids_when_group_key_is_shared_across_postage_buckets(): void
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
            'company_name' => 'Scoped Postage Co',
            'email' => 'scoped-postage@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-SCOPED-POST-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 3000,
            'tax_cents' => 0,
            'total_cents' => 3000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 3000,
        ]);

        $genericOne = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'category' => 'postage',
            'group_key' => 'postage',
            'description' => 'Gen',
            'display_name' => 'Generic Postage',
            'quantity' => 1,
            'unit_price_cents' => 1000,
            'line_total_cents' => 1000,
        ]);
        $genericTwo = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 2,
            'category' => 'postage',
            'group_key' => 'postage',
            'description' => 'Gen 2',
            'display_name' => 'Generic Postage',
            'quantity' => 1,
            'unit_price_cents' => 500,
            'line_total_cents' => 500,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 3,
            'category' => 'postage',
            'group_key' => 'postage',
            'description' => 'USPS line',
            'display_name' => 'Postage (USPS)',
            'quantity' => 1,
            'unit_price_cents' => 1500,
            'line_total_cents' => 1500,
        ]);

        $this->deleteJson("/api/invoices/{$invoice->id}/line-groups/postage", [
            'item_ids' => [(int) $genericOne->id, (int) $genericTwo->id],
        ])->assertOk();

        $invoice->refresh();
        $this->assertSame(0, (int) $invoice->items()->where('display_name', 'Generic Postage')->count());
        $this->assertSame(1, (int) $invoice->items()->where('display_name', 'Postage (USPS)')->count());
        $this->assertSame(1, (int) $invoice->items()->count());
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

    public function test_import_charge_csv_sets_billing_period_from_filename(): void
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
            'company_name' => 'The Style Scout',
            'email' => 'billing@stylescout.example',
        ]);

        $csv = "Charge Name,Charge Type,Qty,Rate,Subtotal\nShip,shipping_label_charge,1,5.00,5.00\n";
        $file = UploadedFile::fake()->createWithContent(
            'saverack_bill_for_thestylescout_608476_821110_2026-04-20--2026-04-26_1777281642_charges_list.csv',
            $csv
        );

        $res = $this->post(
            "/api/client-accounts/{$client->id}/invoice-imports/charges",
            [
                'due_at' => '2026-06-15',
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        );

        $res->assertStatus(201);
        $res->assertJsonPath('invoice.billing_period_start', '2026-04-20');
        $res->assertJsonPath('invoice.billing_period_end', '2026-04-26');
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

    public function test_import_charge_csv_aggregates_configured_on_demand_pick_sku(): void
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
            'company_name' => 'Gummy Import Co',
            'email' => 'gummies@example.test',
        ]);
        ClientAccountOnDemandProduct::query()->create([
            'client_account_id' => $client->id,
            'sku' => 'GSO-CBD-GM',
            'name' => 'CBD Gummies',
            'category' => 'Gummies',
            'price_cents' => 325,
        ]);

        $csv = "Charge Name,Charge Type,SKU (product),Qty,Rate,Subtotal\n"
            ."First pick for the default product profile, of SKU GSO-CBD-GM.,first_pick_charge,GSO-CBD-GM,1,1.00,1.00\n"
            ."2 additional item(s) picked for the default product profile, of SKU GSO-CBD-GM.,pick_remainder_charge,GSO-CBD-GM,1,0.50,0.50\n";
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
        $onDemand = $items->first(static fn (array $item): bool => strtolower((string) ($item['category'] ?? '')) === 'on_demand');
        $this->assertNotNull($onDemand);
        $this->assertSame('CBD Gummies (GSO-CBD-GM)', $onDemand['display_name']);
        $this->assertSame('GSO-CBD-GM', $onDemand['sku']);
        $this->assertSame(2, (int) $onDemand['quantity']);
        $this->assertSame(325, (int) $onDemand['unit_price_cents']);
        $this->assertSame(650, (int) $onDemand['line_total_cents']);
        $this->assertTrue(
            $items->contains(static fn (array $item): bool =>
                strtolower((string) ($item['category'] ?? '')) === 'fulfillment'
                && strtoupper(trim((string) ($item['sku'] ?? ''))) === 'GSO-CBD-GM'
            )
        );
        $this->assertSame(800, (int) $res->json('invoice.total_cents'));
    }

    public function test_import_charge_csv_charge_summary_uses_sku_column_for_on_demand_matching_case_insensitive(): void
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
            'company_name' => 'Charge Summary SKU Co',
            'email' => 'charge-summary-sku@example.test',
        ]);
        ClientAccountOnDemandProduct::query()->create([
            'client_account_id' => $client->id,
            'sku' => 'AURA-ESSENCE-SKIN-CREAM-COMPLETED',
            'name' => 'Aura Essence Skin Cream - Women',
            'category' => ClientAccountOnDemandProduct::CATEGORY_SKIN_CREAM,
            'price_cents' => 325,
        ]);

        $csv = "Charge Name,Charge Type,SKU (product),Charge Qty,Avg Rate,Charge Subtotal\n"
            ."First pick for SKU profile,first_pick_charge,aura-essence-skin-cream-completed,1,1.00,1.00\n"
            ."Additional pick for SKU profile,pick_remainder_charge,AURAESSENCE-SERUM-REFILL,1,0.50,0.50\n";
        $file = UploadedFile::fake()->createWithContent('charge-summary-sku.csv', $csv);

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

        $onDemand = $items->first(static fn (array $item): bool => strtolower((string) ($item['category'] ?? '')) === 'on_demand');
        $this->assertNotNull($onDemand);
        $this->assertSame('AURA-ESSENCE-SKIN-CREAM-COMPLETED', $onDemand['sku']);
        $this->assertSame(1.0, (float) $onDemand['quantity']);
        $this->assertSame(325, (int) $onDemand['line_total_cents']);

        $this->assertTrue(
            $items->contains(static fn (array $item): bool =>
                strtolower((string) ($item['category'] ?? '')) === 'fulfillment'
                && strtoupper(trim((string) ($item['sku'] ?? ''))) === 'AURAESSENCE-SERUM-REFILL'
            )
        );
    }

    public function test_import_charge_csv_keeps_unconfigured_pick_sku_as_fulfillment(): void
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
            'company_name' => 'Regular Import Co',
            'email' => 'regular@example.test',
        ]);

        $csv = "Charge Name,Charge Type,SKU (product),Qty,Rate,Subtotal\n"
            ."First pick for the default product profile, of SKU NOT-CONFIGURED.,first_pick_charge,NOT-CONFIGURED,1,1.00,1.00\n";
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
        $this->assertSame('fulfillment', $items[0]['category']);
        $this->assertStringContainsString('Fulfillment', (string) ($items[0]['display_name'] ?? ''));
    }

    public function test_import_charge_csv_legacy_rows_keep_postage_fulfillment_packaging_returns_and_on_demand(): void
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
            'company_name' => 'Legacy Mix Co',
            'email' => 'legacy-mix@acme.test',
        ]);
        ClientAccountOnDemandProduct::query()->create([
            'client_account_id' => $client->id,
            'sku' => 'GSO-CBD-GM',
            'name' => 'GSO GM',
            'category' => 'Skincare',
            'price_cents' => 250,
            'is_active' => true,
        ]);

        $csv = "\"Date (charge)\",\"Category (charge)\",\"Fee (charge)\",\"Type (charge)\",\"Label (charge)\",\"Description (charge)\",\"Unit rate (charge)\",\"Quantity (charge)\",\"Total (charge)\",\"Order # (shipment)\",\"Carrier (shipment)\",\"Box (shipment)\",\"SKU (product)\",\"Name (product)\"\n"
            ."\"2026-04-20\",\"order\",\"Postage\",\"shipping_label_charge\",\"shipping label\",\"Shipping label 1ZTEST for carrier UPS, method UPS SurePost.\",\"5.44\",\"1\",\"5.44\",\"ORD-POST\",\"UPS\",\"POLY 6x9\",\"\",\"\"\n"
            ."\"2026-04-21\",\"order\",\"Fulfillment\",\"first_pick_charge\",\"first pick\",\"First pick for the default product profile, of SKU GSO-CBD-GM.\",\"1.50\",\"1\",\"1.50\",\"ORD-OD\",\"UPS\",\"POLY 9x12\",\"GSO-CBD-GM\",\"GSO GM\"\n"
            ."\"2026-04-21\",\"order\",\"Fulfillment\",\"pick_remainder_charge\",\"rest of items\",\"2 additional item(s) picked for the default product profile, of SKU GSO-CBD-GM.\",\"0.00\",\"2\",\"0.00\",\"ORD-OD\",\"UPS\",\"POLY 9x12\",\"GSO-CBD-GM\",\"GSO GM\"\n"
            ."\"2026-04-21\",\"order\",\"Fulfillment\",\"first_pick_charge\",\"first pick\",\"First pick for the default product profile, of SKU OTHER-SKU.\",\"1.50\",\"1\",\"1.50\",\"ORD-FUL\",\"UPS\",\"POLY 9x12\",\"OTHER-SKU\",\"Other\"\n"
            ."\"2026-04-21\",\"order\",\"Packaging\",\"box_charge\",\"Packaging\",\"Box BUBBLE MAILER #0 (6 x 10 x 2) used for shipping label 1ZTEST.\",\"0.00\",\"1\",\"0.30\",\"ORD-PKG\",\"UPS\",\"BUBBLE MAILER #0\",\"\",\"\"\n"
            ."\"2026-04-22\",\"returns\",\"Returns\",\"first_return_charge\",\"first return\",\"First return for the default product profile, of SKU Crestline-Pain.\",\"1.85\",\"1\",\"1.85\",\"ORD-RET\",\"\",\"\",\"Crestline-Pain\",\"Crestline Pain\"\n";
        $file = UploadedFile::fake()->createWithContent('legacy-mix-charges.csv', $csv);

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
        $this->assertGreaterThanOrEqual(5, count($items));

        $categories = collect($items)->pluck('category')->map(static fn ($v) => strtolower((string) $v))->values()->all();
        $this->assertContains('postage', $categories);
        $this->assertContains('fulfillment', $categories);
        $this->assertContains('packaging', $categories);
        $this->assertContains('returns', $categories);
        $this->assertContains('on_demand', $categories);

        $onDemand = collect($items)->first(static fn (array $item): bool => strtolower((string) ($item['category'] ?? '')) === 'on_demand');
        $this->assertNotNull($onDemand);
        $this->assertSame('GSO-CBD-GM', $onDemand['sku']);
        $this->assertSame(2.0, (float) $onDemand['quantity']);
        $this->assertSame(500, (int) $onDemand['line_total_cents']);
        $this->assertTrue(
            collect($items)->contains(static fn (array $item): bool =>
                strtolower((string) ($item['category'] ?? '')) === 'fulfillment'
                && strtoupper(trim((string) ($item['sku'] ?? ''))) === 'OTHER-SKU'
            )
        );
    }

    public function test_import_charge_csv_aggregates_on_demand_from_postage_and_pick_rows_same_sku(): void
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
            'company_name' => 'Mixed SKU Co',
            'email' => 'mixed-sku@acme.test',
        ]);
        ClientAccountOnDemandProduct::query()->create([
            'client_account_id' => $client->id,
            'sku' => 'AURA-ESSENCE-SKIN-CREAM-COMPLETED',
            'name' => 'Catalog Item',
            'category' => 'Test',
            'price_cents' => 100,
            'is_active' => true,
        ]);

        $csv = "\"Date (charge)\",\"Category (charge)\",\"Fee (charge)\",\"Type (charge)\",\"Label (charge)\",\"Description (charge)\",\"Unit rate (charge)\",\"Quantity (charge)\",\"Total (charge)\",\"Order # (shipment)\",\"Carrier (shipment)\",\"Box (shipment)\",\"SKU (product)\",\"Name (product)\"\n"
            ."\"2026-04-20\",\"order\",\"Postage\",\"shipping_label_charge\",\"shipping label\",\"Shipping label 1ZTEST for carrier UPS, method UPS SurePost.\",\"5.44\",\"1\",\"5.44\",\"ORD-P\",\"UPS\",\"POLY 6x9\",\"1 aura-essence-skin-cream-completed\",\"Cat\"\n"
            ."\"2026-04-21\",\"order\",\"Fulfillment\",\"first_pick_charge\",\"first pick\",\"First pick line.\",\"1.50\",\"1\",\"1.50\",\"ORD-F\",\"UPS\",\"POLY 9x12\",\" aura-essence-skin-cream-completed \",\"Cat\"\n"
            ."\"2026-04-21\",\"order\",\"Fulfillment\",\"first_pick_charge\",\"first pick\",\"Other pick.\",\"2.00\",\"1\",\"2.00\",\"ORD-O\",\"UPS\",\"POLY 9x12\",\"OTHER-X\",\"Other\"\n";
        $file = UploadedFile::fake()->createWithContent('mixed-od-sku.csv', $csv);

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
        $onDemand = collect($items)->first(static fn (array $item): bool => strtolower((string) ($item['category'] ?? '')) === 'on_demand');
        $this->assertNotNull($onDemand);
        $this->assertSame('AURA-ESSENCE-SKIN-CREAM-COMPLETED', $onDemand['sku']);
        $this->assertSame(2.0, (float) $onDemand['quantity']);
        $this->assertSame(200, (int) $onDemand['line_total_cents']);
        $this->assertTrue(
            collect($items)->contains(static fn (array $item): bool =>
                strtolower((string) ($item['category'] ?? '')) === 'fulfillment'
                && strtoupper(trim((string) ($item['sku'] ?? ''))) === 'OTHER-X'
            )
        );
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

    public function test_public_invoice_storage_by_volume_uses_line_description_and_sku_qty_label(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Vol Public Co',
            'email' => 'vol-public@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PUB-VOL-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 200,
            'tax_cents' => 0,
            'total_cents' => 200,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 200,
        ]);

        $shortA = 'P24C2D2 - US (1.50 cu ft)';
        $shortB = 'X99 - US (2.00 cu ft)';
        $proseA = 'SKU P24C2D2 - US with a volume of 1.50 cu ft stored in location T-29-0 of type Pallet (Small) for 1 day(s).';
        $proseB = 'SKU X99 - US with a volume of 2.00 cu ft stored in location A-1-0 of type Bin for 2 day(s).';

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'category' => 'storage',
            'group_key' => 'storage:vol:line-a',
            'description' => $shortA,
            'display_name' => 'Storage by Volume',
            'service_code' => 'storing_by_volume_daily',
            'quantity' => 1,
            'unit_price_cents' => 100,
            'line_total_cents' => 100,
            'metadata' => ['storage_volume_prose' => $proseA],
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 2,
            'category' => 'storage',
            'group_key' => 'storage:vol:line-b',
            'description' => $shortB,
            'display_name' => 'Storage by Volume',
            'service_code' => 'storing_by_volume_daily',
            'quantity' => 1,
            'unit_price_cents' => 100,
            'line_total_cents' => 100,
            'metadata' => ['storage_volume_prose' => $proseB],
        ]);

        $invoice->refresh()->load('items');
        $data = app(InvoiceService::class)->publicInvoiceHtmlData($invoice);
        $sections = $data['line_sections'];
        $storage = collect($sections)->first(fn (array $s) => strcasecmp((string) ($s['label'] ?? ''), 'Storage') === 0);
        $this->assertNotNull($storage);
        $this->assertSame(' SKUs', (string) ($storage['qty_suffix'] ?? ''));
        $this->assertSame('SKUs', (string) ($storage['storage_qty_metric'] ?? ''));

        $volSvc = collect($storage['services'] ?? [])->first(fn (array $s) => strcasecmp((string) ($s['label'] ?? ''), 'Storage by Volume') === 0);
        $this->assertNotNull($volSvc);
        $this->assertSame('2', (string) ($volSvc['qty_display'] ?? ''));
        $this->assertSame(' SKUs', (string) ($volSvc['qty_suffix'] ?? ''));
        $this->assertSame('SKUs', (string) ($volSvc['storage_qty_metric'] ?? ''));

        $orderLabels = collect($volSvc['orders'] ?? [])->pluck('label')->all();
        $this->assertContains($shortA, $orderLabels);
        $this->assertContains($shortB, $orderLabels);
        $this->assertNotContains($proseA, $orderLabels);
        $this->assertNotContains($proseB, $orderLabels);

        foreach ($volSvc['orders'] ?? [] as $ord) {
            $this->assertSame('1', (string) ($ord['qty_display'] ?? ''));
            $this->assertSame((string) ($ord['unit'] ?? ''), (string) ($ord['line_total'] ?? ''));
        }
    }

    public function test_invoice_presentation_merges_storage_volume_lines_with_same_short_description(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([$this->billingViewPermission()->id]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Vol Merge Co',
            'email' => 'vol-merge@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-VOL-MERGE-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 123,
            'tax_cents' => 0,
            'total_cents' => 123,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 123,
        ]);

        $short = 'P24C2F2SSD2-US (1.44 cu ft)';
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'category' => 'storage',
            'group_key' => 'storage:vol:a',
            'description' => $short,
            'display_name' => 'Storage by Volume',
            'service_code' => 'storing_by_volume_charge',
            'quantity' => 1,
            'unit_price_cents' => 76,
            'line_total_cents' => 76,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 2,
            'category' => 'storage',
            'group_key' => 'storage:vol:b',
            'description' => $short,
            'display_name' => 'Storage by Volume',
            'service_code' => 'storing_by_volume_charge',
            'quantity' => 1,
            'unit_price_cents' => 47,
            'line_total_cents' => 47,
        ]);

        $res = $this->getJson("/api/invoices/{$invoice->id}");
        $res->assertOk();
        $rows = $res->json('presentation.rows');
        $storageRow = collect($rows)->first(fn (array $r) => ($r['name'] ?? '') === 'Storage by Volume');
        $this->assertNotNull($storageRow);
        $this->assertNotEmpty($storageRow['line_group_key'] ?? null, 'Storage by Volume row needs a line_group_key for staff edit/delete actions.');
        $details = $storageRow['details'] ?? [];
        $this->assertCount(1, $details);
        $this->assertSame(2.0, (float) ($details[0]['qty'] ?? 0));
        $this->assertSame(123, (int) ($details[0]['total_cents'] ?? 0));
        $this->assertSame(62, (int) ($details[0]['price_cents'] ?? 0));
        $this->assertEqualsCanonicalizing(
            InvoiceItem::query()->where('invoice_id', $invoice->id)->pluck('id')->all(),
            $details[0]['invoice_item_ids'] ?? []
        );

        $invoice->refresh()->load('items');
        $pub = app(InvoiceService::class)->publicInvoiceHtmlData($invoice);
        $pubStorage = collect($pub['line_sections'] ?? [])->first(fn (array $s) => strcasecmp((string) ($s['label'] ?? ''), 'Storage') === 0);
        $pubVol = collect($pubStorage['services'] ?? [])->first(fn (array $s) => strcasecmp((string) ($s['label'] ?? ''), 'Storage by Volume') === 0);
        $this->assertNotNull($pubVol);
        $this->assertCount(1, $pubVol['orders'] ?? []);
        $this->assertSame('1', (string) (($pubVol['orders'] ?? [])[0]['qty_display'] ?? ''));
        $this->assertSame((string) (($pubVol['orders'] ?? [])[0]['unit'] ?? ''), (string) (($pubVol['orders'] ?? [])[0]['line_total'] ?? ''));
    }

    public function test_invoice_whatsapp_endpoint_sends_provider_payload(): void
    {
        Http::fake([
            'https://wa.example.test/*' => Http::response(['ok' => true], 200),
        ]);
        config()->set('billing.whatsapp.endpoint', 'https://wa.example.test/send');
        config()->set('billing.whatsapp.api_token', 'token-123');
        config()->set('services.whatsapp.phone', '17272554885');

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
            'whatsapp_api_id' => 'wa-chat-15555550123',
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
            ->assertJsonPath('whatsapp.to', 'wa-chat-15555550123')
            ->assertJsonPath('whatsapp.type', 'invoice_reminder');

        Http::assertSent(function ($request) use ($invoice) {
            $data = $request->data();
            return $request->url() === 'https://wa.example.test/send'
                && ($data['chat_id'] ?? null) === 'wa-chat-15555550123'
                && ($data['invoice_id'] ?? null) === $invoice->id
                && ($data['type'] ?? null) === 'invoice_reminder'
                && $request->hasHeader('x-phone', '17272554885')
                && ! empty($data['url']);
        });
    }

    public function test_invoice_whatsapp_endpoint_accepts_payment_failed_type(): void
    {
        Http::fake([
            'https://wa.example.test/*' => Http::response(['ok' => true], 200),
        ]);
        config()->set('billing.whatsapp.endpoint', 'https://wa.example.test/send');
        config()->set('billing.whatsapp.api_token', 'token-123');
        config()->set('services.whatsapp.phone', '17272554885');

        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'WhatsApp Payment Fail Co',
            'email' => 'wafail@acme.test',
            'whatsapp_api_id' => 'wa-chat-15555550999',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-WA-PF-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 5000,
            'tax_cents' => 0,
            'total_cents' => 5000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 12345,
            'billing_period_start' => '2026-04-01',
            'billing_period_end' => '2026-04-30',
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/whatsapp", ['type' => 'payment_failed'])
            ->assertOk()
            ->assertJsonPath('whatsapp.to', 'wa-chat-15555550999')
            ->assertJsonPath('whatsapp.type', 'payment_failed');

        Http::assertSent(function ($request) use ($invoice) {
            $data = $request->data();

            return $request->url() === 'https://wa.example.test/send'
                && ($data['chat_id'] ?? null) === 'wa-chat-15555550999'
                && ($data['invoice_id'] ?? null) === $invoice->id
                && ($data['type'] ?? null) === 'payment_failed'
                && str_contains((string) ($data['message'] ?? ''), 'INV-WA-PF-001')
                && str_contains((string) ($data['message'] ?? ''), '123.45');
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
            'default_payment_type' => 'Credit Card',
            'cc_fee_percent' => 3.50,
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
            'label' => 'CC Fee',
        ])
            ->assertOk()
            ->assertJsonPath('total_cents', 1035)
            ->assertJsonFragment([
                'name' => 'CC Fee',
                'type' => 'Credit Card Fee',
                'total_cents' => 35,
                'groupKey' => 'cc_fee',
            ]);

        $this->postJson("/api/invoices/{$invoice->id}/add-cc-fee", [
            'label' => 'CC Fee',
        ])->assertStatus(422);
    }

    public function test_credit_items_are_saved_as_negative_from_positive_input(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Credit Co',
            'email' => 'credit@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-CREDIT-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/add-item", [
            'description' => 'Manual credit',
            'display_name' => 'Manual credit',
            'category' => 'credits',
            'quantity' => 1,
            'unit_price_cents' => 1100,
            'line_total_cents' => 1100,
        ])
            ->assertOk()
            ->assertJsonPath('subtotal_cents', -1100)
            ->assertJsonPath('total_cents', -1100)
            ->assertJsonPath('items.0.unit_price_cents', -1100)
            ->assertJsonPath('items.0.line_total_cents', -1100);
    }

    public function test_add_item_accepts_staff_ui_category_amazon_prep(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Amazon Prep Co',
            'email' => 'amazon@acme.test',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-AMZ-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/add-item", [
            'description' => 'Amazon prep labor',
            'display_name' => 'Amazon prep labor',
            'category' => 'amazon prep',
            'quantity' => 1,
            'unit_price_cents' => 2500,
        ])
            ->assertOk()
            ->assertJsonPath('total_cents', 2500);

        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'category' => 'amazon prep',
        ]);
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

    public function test_stripe_processing_intent_sets_invoice_processing_and_webhook_success_marks_paid(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Stripe ACH Co',
            'email' => 'ach@acme.test',
            'stripe_customer_id' => 'cus_ach_test',
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-STRIPE-ACH-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1200,
            'tax_cents' => 0,
            'total_cents' => 1200,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1200,
        ]);

        $payments = app(StripeInvoicePaymentService::class);
        $invoiceService = app(InvoiceService::class);

        $pending = PaymentIntent::constructFrom([
            'id' => 'pi_ach_test_001',
            'status' => 'processing',
            'amount' => 1200,
            'amount_received' => 0,
            'latest_charge' => 'ch_ach_test_001',
        ]);

        $pendingResult = $payments->applyIntentToInvoice($invoice, $pending, null, [], $invoiceService);
        $this->assertSame('pending', $pendingResult['result']);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PROCESSING, $invoice->status);
        $this->assertSame(0, (int) $invoice->amount_paid_cents);
        $this->assertSame(1200, (int) $invoice->balance_due_cents);

        $payload = json_encode([
            'id' => 'evt_pi_success_001',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_ach_test_001',
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 1200,
                    'amount_received' => 1200,
                    'latest_charge' => 'ch_ach_test_001',
                    'metadata' => [
                        'invoice_id' => (string) $invoice->id,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $secret = 'whsec_test_001';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $header = "t={$timestamp},v1={$signature}";

        $result = $payments->handleWebhook($payload, $header, $secret, $invoiceService);
        $this->assertTrue((bool) ($result['handled'] ?? false));
        $this->assertSame('payment_intent.succeeded', $result['event_type'] ?? null);
        $this->assertTrue((bool) ($result['applied'] ?? false));

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(1200, (int) $invoice->amount_paid_cents);
        $this->assertSame(0, (int) $invoice->balance_due_cents);
    }

    public function test_stripe_payment_failed_webhook_sets_invoice_failed(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Stripe Fail Co',
            'email' => 'fail@acme.test',
            'stripe_customer_id' => 'cus_fail_test',
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-STRIPE-FAIL-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 2200,
            'tax_cents' => 0,
            'total_cents' => 2200,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 2200,
        ]);

        $payments = app(StripeInvoicePaymentService::class);
        $invoiceService = app(InvoiceService::class);

        $payload = json_encode([
            'id' => 'evt_pi_failed_001',
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'pi_fail_test_001',
                    'object' => 'payment_intent',
                    'status' => 'requires_payment_method',
                    'metadata' => [
                        'invoice_id' => (string) $invoice->id,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $secret = 'whsec_test_002';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $header = "t={$timestamp},v1={$signature}";

        $result = $payments->handleWebhook($payload, $header, $secret, $invoiceService);
        $this->assertTrue((bool) ($result['handled'] ?? false));
        $this->assertSame('payment_intent.payment_failed', $result['event_type'] ?? null);
        $this->assertFalse((bool) ($result['applied'] ?? false));

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAYMENT_FAILED, $invoice->status);
        $this->assertSame(0, (int) $invoice->amount_paid_cents);
        $this->assertSame(2200, (int) $invoice->balance_due_cents);
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

    public function test_invoice_payload_exposes_legacy_status_mapping_fields(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([$this->billingViewPermission()->id]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Legacy Status Co',
            'email' => 'legacy-status@acme.test',
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-LEGACY-STATUS-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'due_at' => now()->subDays(3)->startOfDay(),
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);

        $this->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('status_key', 'past_due')
            ->assertJsonPath('status_label', 'Past Due')
            ->assertJsonPath('status_code', 2);
    }

    public function test_invoice_is_open_not_past_due_within_three_day_grace(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([$this->billingViewPermission()->id]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Grace Period Co',
            'email' => 'grace-period@acme.test',
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-GRACE-STATUS-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'due_at' => now()->subDays(2)->startOfDay(),
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);

        $this->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('status_key', 'open')
            ->assertJsonPath('status_label', 'Open');
    }

    public function test_invoice_list_can_filter_past_due_status(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([$this->billingViewPermission()->id]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Past Due Filter Co',
            'email' => 'past-due-filter@acme.test',
        ]);

        $pastDue = Invoice::query()->create([
            'invoice_number' => 'INV-PAST-DUE-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'due_at' => now()->subDays(4)->startOfDay(),
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);
        Invoice::query()->create([
            'invoice_number' => 'INV-OPEN-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'due_at' => now()->subDay()->startOfDay(),
            'subtotal_cents' => 500,
            'tax_cents' => 0,
            'total_cents' => 500,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 500,
        ]);

        $this->getJson('/api/invoices?status=past_due')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pastDue->id)
            ->assertJsonPath('data.0.status_key', 'past_due');
    }

    public function test_invoice_meta_includes_past_due_status(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([$this->billingViewPermission()->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/invoices/meta')
            ->assertOk()
            ->assertJsonFragment(['past_due']);
    }

    public function test_manual_legacy_status_update_requires_zero_balance_for_paid(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Manual Status Co',
            'email' => 'manual-status@acme.test',
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-MANUAL-STATUS-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/status", [
            'status' => 'paid',
        ])->assertStatus(422);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);

        $invoice->update([
            'amount_paid_cents' => 1000,
            'balance_due_cents' => 0,
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/status", [
            'status' => 'paid',
        ])->assertOk()
            ->assertJsonPath('status_key', 'paid')
            ->assertJsonPath('status_label', 'Paid')
            ->assertJsonPath('status_code', 3);
    }

    public function test_void_invoice_can_be_restored_to_draft_via_status_endpoint(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Void Restore Co',
            'email' => 'void-restore@acme.test',
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-VOID-RESTORE-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_VOID,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/status", [
            'status' => 'draft',
        ])->assertOk()
            ->assertJsonPath('status', Invoice::STATUS_DRAFT)
            ->assertJsonPath('status_key', 'draft')
            ->assertJsonPath('status_label', 'Draft');
    }

    public function test_administrator_can_delete_invoice_regardless_of_status(): void
    {
        $adminRole = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );
        $user = User::factory()->create();
        $user->roles()->attach($adminRole->id);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Admin Delete Co',
            'email' => 'admin-del@acme.test',
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-ADMIN-DEL-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);

        $this->deleteJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Invoice deleted.');

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_staff_with_billing_delete_cannot_delete_sent_invoice(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->billingDeletePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Staff Delete Co',
            'email' => 'staff-del@acme.test',
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-STAFF-DEL-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);

        $this->deleteJson("/api/invoices/{$invoice->id}")->assertForbidden();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_email_invoice_rejected_when_status_is_draft(): void
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
            'company_name' => 'Draft Mail Co',
            'email' => 'draft-mail@test.example',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-DRAFT-EMAIL-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 100,
            'tax_cents' => 0,
            'total_cents' => 100,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 100,
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/email", [
            'recipients' => ['draft-mail@test.example'],
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Send the invoice first before emailing the customer.']);

        Mail::assertNothingSent();
    }

    public function test_whatsapp_rejected_when_status_is_draft(): void
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
            'company_name' => 'Draft WA Co',
            'email' => 'draft-wa@test.example',
            'whatsapp_e164' => '+15555550199',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-DRAFT-WA-001',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 100,
            'tax_cents' => 0,
            'total_cents' => 100,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 100,
        ]);

        $this->postJson("/api/invoices/{$invoice->id}/whatsapp", [
            'type' => 'send_invoice',
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Send the invoice first before messaging via WhatsApp.']);
    }

    public function test_portal_user_lists_only_own_account_invoices(): void
    {
        $accountA = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Portal Co',
            'email' => 'portal@example.com',
            'invoice_share_slug' => 'portal-co',
        ]);
        $accountB = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Other Co',
            'email' => 'other@example.com',
        ]);
        $portalUser = User::factory()->create(['client_account_id' => $accountA->id]);

        Invoice::query()->create([
            'invoice_number' => 'INV-PORTAL-A',
            'client_account_id' => $accountA->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 500,
            'tax_cents' => 0,
            'total_cents' => 500,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 500,
        ]);
        Invoice::query()->create([
            'invoice_number' => 'INV-PORTAL-DRAFT',
            'client_account_id' => $accountA->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 200,
            'tax_cents' => 0,
            'total_cents' => 200,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 200,
        ]);
        Invoice::query()->create([
            'invoice_number' => 'INV-OTHER-B',
            'client_account_id' => $accountB->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 900,
            'tax_cents' => 0,
            'total_cents' => 900,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 900,
        ]);

        Sanctum::actingAs($portalUser);

        $this->getJson('/api/invoices?client_account_id='.$accountB->id)
            ->assertForbidden();

        $response = $this->getJson('/api/invoices');
        $response->assertOk();
        $numbers = collect($response->json('data'))->pluck('invoice_number')->all();
        $this->assertContains('INV-PORTAL-A', $numbers);
        $this->assertNotContains('INV-PORTAL-DRAFT', $numbers);
        $this->assertNotContains('INV-OTHER-B', $numbers);

        $metaStatuses = $this->getJson('/api/invoices/meta')
            ->assertOk()
            ->json('statuses');
        $this->assertNotContains('draft', $metaStatuses);

        $this->getJson('/api/billing/summary')
            ->assertOk()
            ->assertJsonPath('draft_invoice_count', 0);
    }

    public function test_portal_user_cannot_mutate_invoice(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Portal Mutate Co',
            'email' => 'mutate@example.com',
        ]);
        $portalUser = User::factory()->create(['client_account_id' => $client->id]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PORTAL-MUT',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1000,
            'tax_cents' => 0,
            'total_cents' => 1000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1000,
        ]);

        Sanctum::actingAs($portalUser);

        $this->postJson("/api/invoices/{$invoice->id}/record-payment", [
            'amount_cents' => 1000,
        ])->assertForbidden();
    }

    public function test_portal_user_can_get_share_link_for_own_invoice(): void
    {
        $client = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Share Co',
            'email' => 'share@example.com',
            'invoice_share_slug' => 'share-co',
        ]);
        $portalUser = User::factory()->create(['client_account_id' => $client->id]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PORTAL-SHARE',
            'client_account_id' => $client->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 1200,
            'tax_cents' => 0,
            'total_cents' => 1200,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 1200,
        ]);

        Sanctum::actingAs($portalUser);

        $response = $this->postJson("/api/invoices/{$invoice->id}/share-link");
        $response->assertOk()
            ->assertJsonStructure(['customer_view_url', 'customer_pdf_url']);
        $viewUrl = (string) $response->json('customer_view_url');
        $this->assertStringStartsWith(url('/billing-invoice/share-co/'), $viewUrl);
    }

    public function test_portal_billing_summary_is_scoped_to_account(): void
    {
        $accountA = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Summary A',
            'email' => 'a@example.com',
        ]);
        $accountB = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Summary B',
            'email' => 'b@example.com',
        ]);
        $portalUser = User::factory()->create(['client_account_id' => $accountA->id]);

        Invoice::query()->create([
            'invoice_number' => 'INV-SUM-A',
            'client_account_id' => $accountA->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 3000,
            'tax_cents' => 0,
            'total_cents' => 3000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 3000,
        ]);
        Invoice::query()->create([
            'invoice_number' => 'INV-SUM-B',
            'client_account_id' => $accountB->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => 9000,
            'tax_cents' => 0,
            'total_cents' => 9000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 9000,
        ]);

        Sanctum::actingAs($portalUser);

        $this->getJson('/api/billing/summary')
            ->assertOk()
            ->assertJsonPath('open_balance_due_cents', 3000);
    }

    public function test_import_asendia_duties_taxes_csv_creates_draft_invoice_with_breakdown(): void
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
            'company_name' => 'Duties Co',
            'email' => 'duties@acme.test',
        ]);
        $client->refresh();

        $csv = "Invoice Number,Order Number,Product,Duty,Tax\n"
            ."26B016694,#20177,Duty & Taxes,41.02,81.56\n"
            ."26B016694,#20163,Duty & Taxes,26.45,49.49\n";
        $file = UploadedFile::fake()->createWithContent('asendia-duties.csv', $csv);

        $res = $this->post(
            "/api/client-accounts/{$client->id}/invoice-imports/duties-taxes-asendia",
            [
                'due_at' => '2026-06-15',
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        );

        $res->assertStatus(201)
            ->assertJsonPath('invoice.status', Invoice::STATUS_DRAFT)
            ->assertJsonPath('import.import_type', 'duties_taxes_csv');

        $items = $res->json('invoice.items') ?? [];
        $this->assertCount(4, $items);

        $duties = array_values(array_filter($items, static fn ($i) => ($i['display_name'] ?? '') === 'International Duties (Asendia)'));
        $taxes = array_values(array_filter($items, static fn ($i) => ($i['display_name'] ?? '') === 'International Taxes (Asendia)'));
        $this->assertCount(2, $duties);
        $this->assertCount(2, $taxes);
        $this->assertSame(4102, (int) ($duties[0]['line_total_cents'] ?? 0));
        $this->assertSame(2645, (int) ($duties[1]['line_total_cents'] ?? 0));
        $this->assertSame(8156, (int) ($taxes[0]['line_total_cents'] ?? 0));
        $this->assertSame(4949, (int) ($taxes[1]['line_total_cents'] ?? 0));
        $this->assertSame('#20177', $duties[0]['metadata']['order_number'] ?? null);
        $this->assertSame('#20163', $taxes[1]['metadata']['order_number'] ?? null);

        $invoiceId = $res->json('invoice.id');
        $this->assertIsInt($invoiceId);
        $show = $this->getJson("/api/invoices/{$invoiceId}");
        $show->assertOk();

        $presentationRows = collect($show->json('presentation.rows') ?? [])
            ->filter(static fn ($row) => ($row['type'] ?? '') === 'Duties & Taxes')
            ->values()
            ->all();
        $this->assertCount(2, $presentationRows);

        $dutiesRow = collect($presentationRows)->firstWhere('name', 'International Duties (Asendia)');
        $taxesRow = collect($presentationRows)->firstWhere('name', 'International Taxes (Asendia)');
        $this->assertNotNull($dutiesRow);
        $this->assertNotNull($taxesRow);
        $this->assertCount(2, $dutiesRow['details'] ?? []);
        $this->assertCount(2, $taxesRow['details'] ?? []);
        $this->assertContains('#20177', array_column($dutiesRow['details'], 'order_number'));
        $this->assertContains('#20163', array_column($taxesRow['details'], 'order_number'));
    }

    public function test_import_asendia_duties_taxes_csv_requires_order_number_column(): void
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
            'company_name' => 'Duties Missing Col',
            'email' => 'duties-missing@acme.test',
        ]);
        $client->refresh();

        $csv = "Invoice Number,Product,Duty,Tax\n"
            ."26B016694,Duty & Taxes,41.02,81.56\n";
        $file = UploadedFile::fake()->createWithContent('asendia-bad.csv', $csv);

        $this->post(
            "/api/client-accounts/{$client->id}/invoice-imports/duties-taxes-asendia",
            [
                'due_at' => '2026-06-15',
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        )->assertStatus(500);
    }

    public function test_import_asendia_duties_taxes_csv_skips_zero_duty_amount(): void
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
            'company_name' => 'Duties Zero',
            'email' => 'duties-zero@acme.test',
        ]);
        $client->refresh();

        $csv = "Order Number,Product,Duty,Tax\n"
            ."#30001,Duty & Taxes,0,12.50\n";
        $file = UploadedFile::fake()->createWithContent('asendia-zero-duty.csv', $csv);

        $res = $this->post(
            "/api/client-accounts/{$client->id}/invoice-imports/duties-taxes-asendia",
            [
                'due_at' => '2026-06-15',
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        );

        $res->assertStatus(201);
        $items = $res->json('invoice.items') ?? [];
        $this->assertCount(1, $items);
        $this->assertSame('International Taxes (Asendia)', $items[0]['display_name'] ?? null);
        $this->assertSame(1250, (int) ($items[0]['line_total_cents'] ?? 0));
        $this->assertSame('#30001', $items[0]['metadata']['order_number'] ?? null);
    }

    public function test_import_ups_duties_taxes_csv_creates_draft_with_single_service_breakdown(): void
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
            'company_name' => 'UPS Duties Co',
            'email' => 'ups-duties@acme.test',
        ]);
        $client->refresh();

        $csv = "Reference No.1,Billed Charge\n"
            ."#20177,122.58\n"
            ."#20163,75.94\n";
        $file = UploadedFile::fake()->createWithContent('ups-duties.csv', $csv);

        $res = $this->post(
            "/api/client-accounts/{$client->id}/invoice-imports/duties-taxes-ups",
            [
                'due_at' => '2026-06-15',
                'file' => $file,
            ],
            ['Accept' => 'application/json']
        );

        $res->assertStatus(201)
            ->assertJsonPath('invoice.status', Invoice::STATUS_DRAFT)
            ->assertJsonPath('import.import_type', 'ups_duties_taxes_csv');

        $items = $res->json('invoice.items') ?? [];
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertSame('International Duties & Taxes (UPS)', $item['display_name'] ?? null);
        }
        $this->assertSame(12258, (int) ($items[0]['line_total_cents'] ?? 0));
        $this->assertSame(7594, (int) ($items[1]['line_total_cents'] ?? 0));
        $this->assertSame('#20177', $items[0]['metadata']['order_number'] ?? null);
        $this->assertSame('#20163', $items[1]['metadata']['order_number'] ?? null);

        $invoiceId = $res->json('invoice.id');
        $this->assertIsInt($invoiceId);
        $show = $this->getJson("/api/invoices/{$invoiceId}");
        $show->assertOk();

        $presentationRows = collect($show->json('presentation.rows') ?? [])
            ->filter(static fn ($row) => ($row['type'] ?? '') === 'Duties & Taxes')
            ->values()
            ->all();
        $this->assertCount(1, $presentationRows);

        $upsRow = collect($presentationRows)->firstWhere('name', 'International Duties & Taxes (UPS)');
        $this->assertNotNull($upsRow);
        $this->assertCount(2, $upsRow['details'] ?? []);
        $this->assertContains('#20177', array_column($upsRow['details'], 'order_number'));
        $this->assertContains('#20163', array_column($upsRow['details'], 'order_number'));
    }
}
