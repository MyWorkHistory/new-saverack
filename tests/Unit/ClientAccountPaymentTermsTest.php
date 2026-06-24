<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Support\ClientAccountBillingPreferences;
use Carbon\Carbon;
use Tests\TestCase;

class ClientAccountPaymentTermsTest extends TestCase
{
    public function test_normalize_payment_terms_days_defaults_to_one(): void
    {
        $this->assertSame(1, ClientAccountBillingPreferences::normalizePaymentTermsDays(null));
        $this->assertSame(1, ClientAccountBillingPreferences::normalizePaymentTermsDays(0));
        $this->assertSame(1, ClientAccountBillingPreferences::normalizePaymentTermsDays(-3));
    }

    public function test_normalize_payment_terms_days_caps_at_max(): void
    {
        $this->assertSame(365, ClientAccountBillingPreferences::normalizePaymentTermsDays(500));
    }

    public function test_invoice_due_date_uses_account_payment_terms(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 15:30:00'));

        $account = new ClientAccount(['payment_terms_days' => 5]);
        $due = ClientAccountBillingPreferences::invoiceDueDate($account);

        $this->assertSame('2026-06-07', $due->toDateString());

        Carbon::setTestNow();
    }

    public function test_invoice_due_date_defaults_to_one_day_when_terms_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 09:00:00'));

        $account = new ClientAccount();
        $due = ClientAccountBillingPreferences::invoiceDueDate($account);

        $this->assertSame('2026-06-03', $due->toDateString());

        Carbon::setTestNow();
    }

    public function test_invoice_due_date_uses_base_date_when_provided(): void
    {
        $account = new ClientAccount(['payment_terms_days' => 3]);
        $base = Carbon::parse('2026-01-10');
        $due = ClientAccountBillingPreferences::invoiceDueDate($account, $base);

        $this->assertSame('2026-01-13', $due->toDateString());
    }
}
