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

    public function test_payment_terms_label_uses_net_format(): void
    {
        $this->assertSame('Net 1', ClientAccountBillingPreferences::paymentTermsLabel(1));
        $this->assertSame('Net 5', ClientAccountBillingPreferences::paymentTermsLabel(5));
    }

    public function test_effective_payment_terms_prefers_invoice_override(): void
    {
        $account = new ClientAccount(['payment_terms_days' => 5]);

        $this->assertSame(
            'Net 30',
            ClientAccountBillingPreferences::effectivePaymentTerms('Net 30', $account)
        );
        $this->assertSame(
            'Net 5',
            ClientAccountBillingPreferences::effectivePaymentTerms(null, $account)
        );
        $this->assertTrue(ClientAccountBillingPreferences::invoicePaymentTermsOverridden('Net 30', $account));
        $this->assertFalse(ClientAccountBillingPreferences::invoicePaymentTermsOverridden(null, $account));
    }

    public function test_stale_net_one_on_invoice_defers_to_account_terms(): void
    {
        $account = new ClientAccount(['payment_terms_days' => 14]);

        $this->assertSame(
            'Net 14',
            ClientAccountBillingPreferences::effectivePaymentTerms('Net 1', $account)
        );
        $this->assertFalse(ClientAccountBillingPreferences::invoicePaymentTermsOverridden('Net 1', $account));
        $this->assertNull(ClientAccountBillingPreferences::invoicePaymentTermsOverride('Net 1', $account));
    }
}
