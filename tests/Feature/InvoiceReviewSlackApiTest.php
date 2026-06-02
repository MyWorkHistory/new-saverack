<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use App\Support\InvoiceReviewReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceReviewSlackApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.slack.bot_token' => 'xoxb-test-token',
            'billing.slack.accounting_channel' => '#accounting',
            'crm.frontend_url' => 'https://app.saverack.com',
        ]);

        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
        ]);
    }

    private function billingViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.view'],
            ['label' => 'View billing', 'module' => 'billing']
        );
    }

    public function test_staff_can_send_invoice_review_to_slack(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach($this->billingViewPermission()->id);
        Sanctum::actingAs($user);

        $account = ClientAccount::create([
            'company_name' => 'Spirit Nest',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'spirit@test.com',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => '633947',
            'client_account_id' => $account->id,
            'status' => Invoice::STATUS_OPEN,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'balance_due_cents' => 0,
            'amount_paid_cents' => 0,
        ]);

        $response = $this->postJson('/api/invoices/'.$invoice->id.'/invoice-review', [
            'reason' => InvoiceReviewReason::HIGH_POSTAGE,
            'note' => 'Rates look high on this period.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Invoice review sent to Slack.');

        Http::assertSent(function ($request) use ($invoice) {
            $text = (string) ($request->data()['text'] ?? '');

            return str_contains($text, 'Invoice #633947 - Spirit Nest - High Postage')
                && str_contains($text, 'Note: Rates look high on this period.')
                && str_contains($text, '/admin/billing/invoices/'.$invoice->id);
        });

        $this->assertDatabaseHas('invoice_histories', [
            'invoice_id' => $invoice->id,
            'action' => 'invoice_review_slack_sent',
        ]);
    }

    public function test_portal_user_cannot_send_invoice_review(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Portal Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'portal-co@test.com',
        ]);

        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->billingViewPermission()->id);
        Sanctum::actingAs($user);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-PORTAL',
            'client_account_id' => $account->id,
            'status' => Invoice::STATUS_OPEN,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'balance_due_cents' => 0,
            'amount_paid_cents' => 0,
        ]);

        $this->postJson('/api/invoices/'.$invoice->id.'/invoice-review', [
            'reason' => InvoiceReviewReason::HIGH_POSTAGE,
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_invalid_reason_returns_422(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach($this->billingViewPermission()->id);
        Sanctum::actingAs($user);

        $account = ClientAccount::create([
            'company_name' => 'Test Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'test@test.com',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-422',
            'client_account_id' => $account->id,
            'status' => Invoice::STATUS_OPEN,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'balance_due_cents' => 0,
            'amount_paid_cents' => 0,
        ]);

        $this->postJson('/api/invoices/'.$invoice->id.'/invoice-review', [
            'reason' => 'not_a_valid_reason',
        ])->assertStatus(422);

        Http::assertNothingSent();
    }
}
