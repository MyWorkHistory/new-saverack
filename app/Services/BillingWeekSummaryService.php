<?php

namespace App\Services;

use App\Models\BillingWeekSummary;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Billing\InvoiceLineCategory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BillingWeekSummaryService
{
    /**
     * Monday of the most recently completed Mon–Sun week (app timezone).
     */
    public function defaultCompletedWeekStart(?Carbon $now = null): Carbon
    {
        $today = ($now ?? now())->copy()->startOfDay();
        $currentMonday = $today->copy()->startOfWeek(Carbon::MONDAY);

        return $currentMonday->copy()->subWeek()->startOfDay();
    }

    /**
     * Normalize any date to that week's Monday.
     */
    public function mondayOfWeek(Carbon $date): Carbon
    {
        return $date->copy()->startOfDay()->startOfWeek(Carbon::MONDAY);
    }

    public function weekEndFromStart(Carbon $weekStart): Carbon
    {
        return $weekStart->copy()->startOfDay()->addDays(6)->startOfDay();
    }

    /**
     * Aggregate invoice lines for the week and upsert the snapshot.
     */
    public function generateWeek(Carbon $weekStart, ?User $actor = null): BillingWeekSummary
    {
        $weekStart = $this->mondayOfWeek($weekStart);
        $weekEnd = $this->weekEndFromStart($weekStart);
        $totals = $this->aggregateWeek($weekStart, $weekEnd);

        $summary = BillingWeekSummary::query()->updateOrCreate(
            ['week_start' => $weekStart->toDateString()],
            [
                'week_end' => $weekEnd->toDateString(),
                'total_billed_cents' => $totals['total_billed_cents'],
                'fulfillment_cents' => $totals['fulfillment_cents'],
                'postage_cents' => $totals['postage_cents'],
                'materials_cents' => $totals['materials_cents'],
                'returns_cents' => $totals['returns_cents'],
                'custom_work_cents' => $totals['custom_work_cents'],
                'wholesale_cents' => $totals['wholesale_cents'],
                'invoice_count' => $totals['invoice_count'],
                'generated_at' => now(),
                'generated_by_user_id' => $actor !== null ? $actor->id : null,
            ]
        );

        return $summary->fresh();
    }

    /**
     * @return array{
     *     total_billed_cents: int,
     *     fulfillment_cents: int,
     *     postage_cents: int,
     *     materials_cents: int,
     *     returns_cents: int,
     *     custom_work_cents: int,
     *     wholesale_cents: int,
     *     invoice_count: int
     * }
     */
    public function aggregateWeek(Carbon $weekStart, Carbon $weekEnd): array
    {
        $start = $weekStart->toDateString();
        $end = $weekEnd->toDateString();

        $categoryMap = [
            InvoiceLineCategory::FULFILLMENT => 'fulfillment_cents',
            InvoiceLineCategory::POSTAGE => 'postage_cents',
            InvoiceLineCategory::PACKAGING => 'materials_cents',
            InvoiceLineCategory::RETURNS => 'returns_cents',
            InvoiceLineCategory::AD_HOC => 'custom_work_cents',
            InvoiceLineCategory::WHOLESALE => 'wholesale_cents',
        ];
        $tracked = array_keys($categoryMap);

        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.status', '!=', Invoice::STATUS_VOID)
            ->whereNotNull('invoices.billing_period_start')
            ->whereNotNull('invoices.billing_period_end')
            ->whereDate('invoices.billing_period_start', '<=', $end)
            ->whereDate('invoices.billing_period_end', '>=', $start)
            ->whereIn('invoice_items.category', $tracked)
            ->groupBy('invoice_items.category')
            ->selectRaw('invoice_items.category as category, SUM(invoice_items.line_total_cents) as total_cents')
            ->get();

        $out = [
            'fulfillment_cents' => 0,
            'postage_cents' => 0,
            'materials_cents' => 0,
            'returns_cents' => 0,
            'custom_work_cents' => 0,
            'wholesale_cents' => 0,
        ];

        foreach ($rows as $row) {
            $cat = strtolower(trim((string) ($row->category ?? '')));
            $field = $categoryMap[$cat] ?? null;
            if ($field === null) {
                continue;
            }
            $out[$field] = (int) ($row->total_cents ?? 0);
        }

        $out['total_billed_cents'] = array_sum($out);

        $invoiceCount = (int) DB::table('invoices')
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->whereNotNull('billing_period_start')
            ->whereNotNull('billing_period_end')
            ->whereDate('billing_period_start', '<=', $end)
            ->whereDate('billing_period_end', '>=', $start)
            ->whereExists(function ($q) use ($tracked) {
                $q->select(DB::raw(1))
                    ->from('invoice_items')
                    ->whereColumn('invoice_items.invoice_id', 'invoices.id')
                    ->whereIn('invoice_items.category', $tracked);
            })
            ->count();

        $out['invoice_count'] = $invoiceCount;

        return $out;
    }

    public function getWeek(Carbon $weekStart): ?BillingWeekSummary
    {
        $monday = $this->mondayOfWeek($weekStart)->toDateString();

        return BillingWeekSummary::query()->whereDate('week_start', $monday)->first();
    }

    /**
     * Latest snapshot by week_start, plus the prior calendar week if stored.
     *
     * @return array{
     *     current: array<string, mixed>|null,
     *     previous: array<string, mixed>|null,
     *     comparison: array{delta_cents: int|null, percent: float|null}|null,
     *     default_week_start: string
     * }
     */
    public function dashboardPayload(?Carbon $weekStart = null): array
    {
        $defaultStart = $this->defaultCompletedWeekStart();

        if ($weekStart !== null) {
            $current = $this->getWeek($weekStart);
        } else {
            $current = BillingWeekSummary::query()
                ->orderByDesc('week_start')
                ->first();
        }

        $previous = null;
        if ($current !== null && $current->week_start !== null) {
            $prevStart = $current->week_start->copy()->subWeek();
            $previous = $this->getWeek($prevStart);
        }

        $comparison = null;
        if ($current !== null) {
            $comparison = $this->compare(
                (int) $current->total_billed_cents,
                $previous !== null ? (int) $previous->total_billed_cents : null
            );
        }

        return [
            'current' => $current !== null ? $this->toApiArray($current) : null,
            'previous' => $previous !== null ? $this->toApiArray($previous) : null,
            'comparison' => $comparison,
            'default_week_start' => $defaultStart->toDateString(),
        ];
    }

    /**
     * @return array{delta_cents: int|null, percent: float|null}
     */
    public function compare(int $currentCents, ?int $previousCents): array
    {
        if ($previousCents === null) {
            return [
                'delta_cents' => null,
                'percent' => null,
            ];
        }

        $delta = $currentCents - $previousCents;
        $percent = null;
        if ($previousCents !== 0) {
            $percent = round(($delta / abs($previousCents)) * 100, 1);
        }

        return [
            'delta_cents' => $delta,
            'percent' => $percent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(BillingWeekSummary $summary): array
    {
        return [
            'id' => $summary->id,
            'week_start' => $summary->week_start !== null ? $summary->week_start->toDateString() : null,
            'week_end' => $summary->week_end !== null ? $summary->week_end->toDateString() : null,
            'total_billed_cents' => (int) $summary->total_billed_cents,
            'fulfillment_cents' => (int) $summary->fulfillment_cents,
            'postage_cents' => (int) $summary->postage_cents,
            'materials_cents' => (int) $summary->materials_cents,
            'returns_cents' => (int) $summary->returns_cents,
            'custom_work_cents' => (int) $summary->custom_work_cents,
            'wholesale_cents' => (int) $summary->wholesale_cents,
            'invoice_count' => (int) $summary->invoice_count,
            'generated_at' => $summary->generated_at !== null
                ? $summary->generated_at->toIso8601String()
                : null,
            'generated_by_user_id' => $summary->generated_by_user_id,
        ];
    }
}
