<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Permission;
use App\Models\User;
use App\Services\BillingWeekSummaryService;
use App\Support\Billing\InvoiceLineCategory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingWeekSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    private function billingViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.view'],
            ['label' => 'View billing', 'module' => 'billing']
        );
    }

    private function actingBillingStaff(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach($this->billingViewPermission()->id);
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeInvoiceWithItem(
        ClientAccount $account,
        string $status,
        string $periodStart,
        string $periodEnd,
        string $category,
        int $lineTotalCents
    ): Invoice {
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-'.uniqid(),
            'client_account_id' => $account->id,
            'status' => $status,
            'currency' => 'USD',
            'subtotal_cents' => $lineTotalCents,
            'tax_cents' => 0,
            'total_cents' => $lineTotalCents,
            'amount_paid_cents' => 0,
            'balance_due_cents' => $lineTotalCents,
            'billing_period_start' => $periodStart,
            'billing_period_end' => $periodEnd,
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'category' => $category,
            'display_name' => $category,
            'quantity' => 1,
            'unit_price_cents' => $lineTotalCents,
            'line_total_cents' => $lineTotalCents,
        ]);

        return $invoice;
    }

    public function test_guest_cannot_access_week_summaries(): void
    {
        $this->getJson('/api/billing/week-summaries')->assertUnauthorized();
        $this->postJson('/api/billing/week-summaries/generate')->assertUnauthorized();
    }

    public function test_generate_aggregates_categories_and_excludes_void(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00')); // Monday
        $this->actingBillingStaff();

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Week Summary Co',
            'email' => 'week-summary@example.test',
        ]);

        // Completed week: Mon 2026-07-13 .. Sun 2026-07-19
        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_SENT,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::FULFILLMENT,
            10000
        );
        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_SENT,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::FULFILLMENT,
            2500
        );
        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_PAID,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::POSTAGE,
            3000
        );
        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_SENT,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::PACKAGING,
            1500
        );
        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_SENT,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::RETURNS,
            800
        );
        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_SENT,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::AD_HOC,
            1200
        );
        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_SENT,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::WHOLESALE,
            4000
        );
        // Void — ignored
        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_VOID,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::FULFILLMENT,
            99999
        );
        // Storage — not in v1 buckets
        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_SENT,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::STORAGE,
            5000
        );

        $response = $this->postJson('/api/billing/week-summaries/generate');
        $response->assertOk()
            ->assertJsonPath('generated.week_start', '2026-07-13')
            ->assertJsonPath('generated.week_end', '2026-07-19')
            ->assertJsonPath('generated.fulfillment_cents', 12500)
            ->assertJsonPath('generated.postage_cents', 3000)
            ->assertJsonPath('generated.materials_cents', 1500)
            ->assertJsonPath('generated.returns_cents', 800)
            ->assertJsonPath('generated.custom_work_cents', 1200)
            ->assertJsonPath('generated.wholesale_cents', 4000)
            ->assertJsonPath('generated.total_billed_cents', 23000)
            ->assertJsonPath('current.total_billed_cents', 23000);

        Carbon::setTestNow();
    }

    public function test_generate_upserts_same_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));
        $this->actingBillingStaff();

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Upsert Co',
            'email' => 'upsert@example.test',
        ]);

        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_SENT,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::POSTAGE,
            1000
        );

        $this->postJson('/api/billing/week-summaries/generate', [
            'week_start' => '2026-07-15',
        ])->assertOk()->assertJsonPath('generated.postage_cents', 1000);

        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_SENT,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::POSTAGE,
            500
        );

        $this->postJson('/api/billing/week-summaries/generate', [
            'week_start' => '2026-07-13',
        ])->assertOk()->assertJsonPath('generated.postage_cents', 1500);

        $this->assertDatabaseCount('billing_week_summaries', 1);

        Carbon::setTestNow();
    }

    public function test_index_compares_week_over_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));
        $this->actingBillingStaff();

        $service = app(BillingWeekSummaryService::class);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Compare Co',
            'email' => 'compare@example.test',
        ]);
        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_SENT,
            '2026-07-06',
            '2026-07-12',
            InvoiceLineCategory::FULFILLMENT,
            10000
        );
        $this->makeInvoiceWithItem(
            $account,
            Invoice::STATUS_SENT,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::FULFILLMENT,
            15000
        );

        $service->generateWeek(Carbon::parse('2026-07-06'));
        $service->generateWeek(Carbon::parse('2026-07-13'));

        $response = $this->getJson('/api/billing/week-summaries?week_start=2026-07-13');
        $response->assertOk()
            ->assertJsonPath('current.total_billed_cents', 15000)
            ->assertJsonPath('previous.total_billed_cents', 10000)
            ->assertJsonPath('comparison.delta_cents', 5000)
            ->assertJsonPath('comparison.percent', 50);

        Carbon::setTestNow();
    }
}
