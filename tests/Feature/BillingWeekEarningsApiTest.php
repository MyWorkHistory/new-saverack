<?php

namespace Tests\Feature;

use App\Models\BillingWeekEarning;
use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Permission;
use App\Models\PricingFeeTemplate;
use App\Models\User;
use App\Support\Billing\InvoiceLineCategory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingWeekEarningsApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingBillingStaff(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $perm = Permission::query()->firstOrCreate(
            ['key' => 'billing.view'],
            ['label' => 'View billing', 'module' => 'billing']
        );
        $user->permissions()->attach($perm->id);
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeAccount(string $name): ClientAccount
    {
        return ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'@example.test',
        ]);
    }

    private function makeInvoiceWithItem(
        ClientAccount $account,
        string $periodStart,
        string $periodEnd,
        string $category,
        string $displayName,
        int $qty,
        int $unitPriceCents
    ): Invoice {
        $lineTotal = $qty * $unitPriceCents;
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-'.uniqid(),
            'client_account_id' => $account->id,
            'status' => Invoice::STATUS_SENT,
            'currency' => 'USD',
            'subtotal_cents' => $lineTotal,
            'tax_cents' => 0,
            'total_cents' => $lineTotal,
            'amount_paid_cents' => 0,
            'balance_due_cents' => $lineTotal,
            'billing_period_start' => $periodStart,
            'billing_period_end' => $periodEnd,
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'category' => $category,
            'display_name' => $displayName,
            'quantity' => $qty,
            'unit_price_cents' => $unitPriceCents,
            'line_total_cents' => $lineTotal,
        ]);

        return $invoice;
    }

    public function test_guest_cannot_access_week_earnings(): void
    {
        $this->getJson('/api/billing/week-earnings')->assertUnauthorized();
        $this->postJson('/api/billing/week-earnings/generate')->assertUnauthorized();
    }

    public function test_generate_matches_packaging_cost_and_computes_earnings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));
        $this->actingBillingStaff();

        $account = $this->makeAccount('Fanfit Earnings');
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => PricingFeeTemplate::CATEGORY_PACKAGING,
            'line_code' => 'template_bubble',
            'label' => 'BUBBLE MAILER #0',
            'amount' => 0.3,
            'cost' => 0.2,
            'currency' => 'USD',
            'sort_order' => 1,
        ]);

        // qty 2 × $0.30 = $0.60 billed; cost 2 × $0.20 = $0.40; earnings $0.20 = 20 cents
        $this->makeInvoiceWithItem(
            $account,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::PACKAGING,
            'BUBBLE MAILER #0',
            2,
            30
        );

        $response = $this->postJson('/api/billing/week-earnings/generate', [
            'week_start' => '2026-07-13',
        ]);
        $response->assertStatus(202)
            ->assertJsonPath('status', BillingWeekEarning::STATUS_COMPLETED)
            ->assertJsonPath('earning.materials_cents', 20)
            ->assertJsonPath('earning.total_cents', 20)
            ->assertJsonPath('earning.unmatched_count', 0)
            ->assertJsonPath('earning.matched_line_count', 1);

        Carbon::setTestNow();
    }

    public function test_unmatched_when_fee_missing_or_cost_blank(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));
        $this->actingBillingStaff();

        $account = $this->makeAccount('Missing Fee Co');
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => PricingFeeTemplate::CATEGORY_PACKAGING,
            'line_code' => 'template_poly',
            'label' => 'POLY 6x9',
            'amount' => 0.15,
            'cost' => null,
            'currency' => 'USD',
            'sort_order' => 1,
        ]);

        $this->makeInvoiceWithItem(
            $account,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::PACKAGING,
            'BOX 7x7x7',
            1,
            63
        );
        $this->makeInvoiceWithItem(
            $account,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::PACKAGING,
            'POLY 6x9',
            2,
            15
        );

        $gen = $this->postJson('/api/billing/week-earnings/generate', [
            'week_start' => '2026-07-13',
        ]);
        $gen->assertStatus(202)
            ->assertJsonPath('earning.unmatched_count', 2)
            ->assertJsonPath('earning.matched_line_count', 0)
            ->assertJsonPath('earning.total_cents', 0);

        $id = (int) $gen->json('id');
        $unmatched = $this->getJson('/api/billing/week-earnings/'.$id.'/unmatched');
        $unmatched->assertOk()->assertJsonCount(2, 'items');
        $reasons = collect($unmatched->json('items'))->pluck('reason')->sort()->values()->all();
        $this->assertSame(['cost_missing', 'fee_not_found'], $reasons);

        Carbon::setTestNow();
    }

    public function test_regenerate_updates_totals_and_unmatched(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));
        $this->actingBillingStaff();

        $account = $this->makeAccount('Regen Earnings Co');
        $this->makeInvoiceWithItem(
            $account,
            '2026-07-13',
            '2026-07-19',
            InvoiceLineCategory::PACKAGING,
            'BUBBLE MAILER #0',
            1,
            30
        );

        $first = $this->postJson('/api/billing/week-earnings/generate', [
            'week_start' => '2026-07-13',
        ]);
        $first->assertStatus(202)->assertJsonPath('earning.unmatched_count', 1);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => PricingFeeTemplate::CATEGORY_PACKAGING,
            'line_code' => 'template_bubble',
            'label' => 'BUBBLE MAILER #0',
            'amount' => 0.3,
            'cost' => 0.2,
            'currency' => 'USD',
            'sort_order' => 1,
        ]);

        $second = $this->postJson('/api/billing/week-earnings/generate', [
            'week_start' => '2026-07-13',
        ]);
        $second->assertStatus(202)
            ->assertJsonPath('earning.unmatched_count', 0)
            ->assertJsonPath('earning.materials_cents', 10)
            ->assertJsonPath('earning.matched_line_count', 1);

        $this->assertDatabaseCount('billing_week_earnings', 1);
        $this->assertDatabaseCount('billing_week_earning_unmatched_items', 0);

        $list = $this->getJson('/api/billing/week-earnings?from=2026-07-13&to=2026-07-13');
        $list->assertOk()
            ->assertJsonPath('totals.week_count', 1)
            ->assertJsonPath('weeks.0.materials_cents', 10);

        Carbon::setTestNow();
    }
}
