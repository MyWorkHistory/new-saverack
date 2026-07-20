<?php

namespace Tests\Unit;

use App\Services\BillingWeekSummaryService;
use Carbon\Carbon;
use Tests\TestCase;

final class BillingWeekSummaryServiceTest extends TestCase
{
    public function test_default_completed_week_is_previous_monday_sunday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 15:00:00')); // Monday
        $service = app(BillingWeekSummaryService::class);
        $start = $service->defaultCompletedWeekStart();
        $this->assertSame('2026-07-13', $start->toDateString());
        $this->assertSame('2026-07-19', $service->weekEndFromStart($start)->toDateString());
        Carbon::setTestNow();
    }

    public function test_monday_of_week_normalizes_midweek_date(): void
    {
        $service = app(BillingWeekSummaryService::class);
        $monday = $service->mondayOfWeek(Carbon::parse('2026-07-16'));
        $this->assertSame('2026-07-13', $monday->toDateString());
    }

    public function test_compare_percent_and_delta(): void
    {
        $service = app(BillingWeekSummaryService::class);
        $up = $service->compare(15000, 10000);
        $this->assertSame(5000, $up['delta_cents']);
        $this->assertSame(50.0, $up['percent']);

        $down = $service->compare(8000, 10000);
        $this->assertSame(-2000, $down['delta_cents']);
        $this->assertSame(-20.0, $down['percent']);

        $zeroPrev = $service->compare(5000, 0);
        $this->assertSame(5000, $zeroPrev['delta_cents']);
        $this->assertNull($zeroPrev['percent']);

        $missing = $service->compare(5000, null);
        $this->assertNull($missing['delta_cents']);
        $this->assertNull($missing['percent']);
    }
}
