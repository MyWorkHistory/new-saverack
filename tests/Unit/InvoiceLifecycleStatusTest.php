<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Support\Billing\InvoiceLifecycleStatus;
use Carbon\Carbon;
use Tests\TestCase;

class InvoiceLifecycleStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_past_due_starts_three_days_after_due_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00'));

        $invoice = new Invoice([
            'status' => Invoice::STATUS_SENT,
            'balance_due_cents' => 1000,
            'due_at' => Carbon::parse('2026-06-23'),
        ]);

        $this->assertTrue(InvoiceLifecycleStatus::isPastDue($invoice));
    }

    public function test_invoice_within_grace_period_is_not_past_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00'));

        $invoice = new Invoice([
            'status' => Invoice::STATUS_SENT,
            'balance_due_cents' => 1000,
            'due_at' => Carbon::parse('2026-06-23'),
        ]);

        $this->assertFalse(InvoiceLifecycleStatus::isPastDue($invoice));
    }

    public function test_processing_invoice_is_never_past_due_even_after_due_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 12:00:00'));

        $invoice = new Invoice([
            'status' => Invoice::STATUS_PROCESSING,
            'balance_due_cents' => 1000,
            'due_at' => Carbon::parse('2026-06-01'),
        ]);

        $this->assertFalse(InvoiceLifecycleStatus::isPastDue($invoice));
    }
}
