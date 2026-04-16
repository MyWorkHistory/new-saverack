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
use Illuminate\Support\Facades\Http;
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
            ->assertSee('Invoice Dates From', false)
            ->assertSee('Invoice Dates To', false);
    }
}
