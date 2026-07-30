<?php

namespace App\Services;

use App\Jobs\GenerateBillingWeekEarningsJob;
use App\Models\BillingWeekEarning;
use App\Models\BillingWeekEarningUnmatchedItem;
use App\Models\ClientAccountFee;
use App\Models\Invoice;
use App\Models\PricingFeeTemplate;
use App\Models\User;
use App\Support\Billing\InvoiceLineCategory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BillingWeekEarningsService
{
    public const REASON_FEE_NOT_FOUND = BillingWeekEarningUnmatchedItem::REASON_FEE_NOT_FOUND;

    public const REASON_COST_MISSING = BillingWeekEarningUnmatchedItem::REASON_COST_MISSING;

    /**
     * Monday of the most recently completed Mon–Sun week.
     */
    public function defaultCompletedWeekStart(?Carbon $now = null): Carbon
    {
        $today = ($now ?? now())->copy()->startOfDay();
        $currentMonday = $today->copy()->startOfWeek(Carbon::MONDAY);

        return $currentMonday->copy()->subWeek()->startOfDay();
    }

    public function mondayOfWeek(Carbon $date): Carbon
    {
        return $date->copy()->startOfDay()->startOfWeek(Carbon::MONDAY);
    }

    public function weekEndFromStart(Carbon $weekStart): Carbon
    {
        return $weekStart->copy()->startOfDay()->addDays(6)->startOfDay();
    }

    /**
     * Queue generation for a week (upsert pending row + dispatch job).
     */
    public function queueGenerate(Carbon $weekStart, ?User $actor = null): BillingWeekEarning
    {
        $weekStart = $this->mondayOfWeek($weekStart);
        $weekEnd = $this->weekEndFromStart($weekStart);

        $earning = BillingWeekEarning::query()->updateOrCreate(
            ['week_start' => $weekStart->toDateString()],
            [
                'week_end' => $weekEnd->toDateString(),
                'status' => BillingWeekEarning::STATUS_PENDING,
                'error_message' => null,
                'generated_by_user_id' => $actor !== null ? $actor->id : null,
            ]
        );

        GenerateBillingWeekEarningsJob::dispatch((int) $earning->id);

        return $earning->fresh();
    }

    /**
     * Run generation synchronously (job handle / tests).
     */
    public function generateWeek(BillingWeekEarning $earning): BillingWeekEarning
    {
        $earning->status = BillingWeekEarning::STATUS_RUNNING;
        $earning->error_message = null;
        $earning->save();

        try {
            $weekStart = $this->mondayOfWeek($earning->week_start);
            $weekEnd = $this->weekEndFromStart($weekStart);
            $result = $this->aggregateWeek($weekStart, $weekEnd);

            DB::transaction(function () use ($earning, $weekStart, $weekEnd, $result) {
                $earning->unmatchedItems()->delete();

                $earning->week_start = $weekStart->toDateString();
                $earning->week_end = $weekEnd->toDateString();
                $earning->fulfillment_cents = $result['fulfillment_cents'];
                $earning->postage_cents = $result['postage_cents'];
                $earning->materials_cents = $result['materials_cents'];
                $earning->returns_cents = $result['returns_cents'];
                $earning->custom_work_cents = $result['custom_work_cents'];
                $earning->wholesale_cents = $result['wholesale_cents'];
                $earning->total_cents = $result['total_cents'];
                $earning->matched_line_count = $result['matched_line_count'];
                $earning->unmatched_count = count($result['unmatched']);
                $earning->status = BillingWeekEarning::STATUS_COMPLETED;
                $earning->error_message = null;
                $earning->generated_at = now();
                $earning->save();

                foreach ($result['unmatched'] as $row) {
                    $earning->unmatchedItems()->create($row);
                }
            });

            return $earning->fresh(['unmatchedItems']);
        } catch (Throwable $e) {
            $earning->status = BillingWeekEarning::STATUS_FAILED;
            $earning->error_message = Str::limit($e->getMessage(), 2000, '');
            $earning->save();
            throw $e;
        }
    }

    /**
     * @return array{
     *     fulfillment_cents: int,
     *     postage_cents: int,
     *     materials_cents: int,
     *     returns_cents: int,
     *     custom_work_cents: int,
     *     wholesale_cents: int,
     *     total_cents: int,
     *     matched_line_count: int,
     *     unmatched: list<array<string, mixed>>
     * }
     */
    public function aggregateWeek(Carbon $weekStart, Carbon $weekEnd): array
    {
        $start = $weekStart->toDateString();
        $end = $weekEnd->toDateString();

        $totals = [
            'fulfillment_cents' => 0,
            'postage_cents' => 0,
            'materials_cents' => 0,
            'returns_cents' => 0,
            'custom_work_cents' => 0,
            'wholesale_cents' => 0,
        ];
        $matchedLineCount = 0;
        $unmatched = [];
        $feeCache = [];

        $tracked = [
            InvoiceLineCategory::FULFILLMENT,
            InvoiceLineCategory::POSTAGE,
            InvoiceLineCategory::PACKAGING,
            InvoiceLineCategory::RETURNS,
            InvoiceLineCategory::AD_HOC,
            InvoiceLineCategory::WHOLESALE,
        ];

        Invoice::query()
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->whereNotNull('billing_period_start')
            ->whereNotNull('billing_period_end')
            ->whereDate('billing_period_start', '<=', $end)
            ->whereDate('billing_period_end', '>=', $start)
            ->orderBy('id')
            ->chunkById(50, function ($invoices) use (
                &$totals,
                &$matchedLineCount,
                &$unmatched,
                &$feeCache,
                $tracked
            ) {
                $invoices->load(['items', 'clientAccount']);
                foreach ($invoices as $invoice) {
                    $accountId = (int) $invoice->client_account_id;
                    if ($accountId <= 0) {
                        continue;
                    }
                    if (! isset($feeCache[$accountId])) {
                        $feeCache[$accountId] = $this->buildFeeLookup($accountId);
                    }
                    $lookup = $feeCache[$accountId];

                    foreach ($invoice->items as $item) {
                        $category = strtolower(trim((string) ($item->category ?? '')));
                        if (! in_array($category, $tracked, true)) {
                            continue;
                        }

                        $field = $this->categoryToSnapshotField($category);
                        if ($field === null) {
                            continue;
                        }

                        $displayName = trim((string) ($item->display_name ?: $item->description ?: ''));
                        if ($displayName === '') {
                            $displayName = 'Untitled';
                        }

                        $billedCents = (int) ($item->line_total_cents ?? 0);
                        $qty = (float) ($item->quantity ?? 0);
                        $match = $this->matchFee($lookup, $category, $displayName, (string) ($item->group_key ?? ''));

                        if ($match['status'] === 'matched') {
                            $costCents = (int) round(((float) $match['cost']) * $qty * 100);
                            $totals[$field] += ($billedCents - $costCents);
                            $matchedLineCount++;
                            continue;
                        }

                        $unmatched[] = [
                            'client_account_id' => $accountId,
                            'invoice_id' => $invoice->id,
                            'invoice_item_id' => $item->id,
                            'category' => $category,
                            'display_name' => Str::limit($displayName, 512, ''),
                            'quantity' => $qty,
                            'billed_cents' => $billedCents,
                            'reason' => $match['reason'],
                        ];
                    }
                }
            });

        $totalCents = array_sum($totals);

        return array_merge($totals, [
            'total_cents' => $totalCents,
            'matched_line_count' => $matchedLineCount,
            'unmatched' => $unmatched,
        ]);
    }

    /**
     * @return array{
     *     weeks: list<array<string, mixed>>,
     *     totals: array<string, int>,
     *     from: string,
     *     to: string
     * }
     */
    public function listWeeks(?Carbon $from = null, ?Carbon $to = null): array
    {
        $defaultEnd = $this->defaultCompletedWeekStart();
        $defaultStart = $defaultEnd->copy()->subWeeks(7);

        if ($from === null && $to === null) {
            $fromMonday = $defaultStart;
            $toMonday = $defaultEnd;
        } else {
            $fromMonday = $from !== null ? $this->mondayOfWeek($from) : $defaultStart;
            $toMonday = $to !== null ? $this->mondayOfWeek($to) : $defaultEnd;
            if ($fromMonday->gt($toMonday)) {
                $tmp = $fromMonday;
                $fromMonday = $toMonday;
                $toMonday = $tmp;
            }
        }

        $rows = BillingWeekEarning::query()
            ->whereDate('week_start', '>=', $fromMonday->toDateString())
            ->whereDate('week_start', '<=', $toMonday->toDateString())
            ->where('status', BillingWeekEarning::STATUS_COMPLETED)
            ->orderBy('week_start')
            ->get();

        $weeks = [];
        $totals = [
            'fulfillment_cents' => 0,
            'postage_cents' => 0,
            'materials_cents' => 0,
            'returns_cents' => 0,
            'custom_work_cents' => 0,
            'wholesale_cents' => 0,
            'total_cents' => 0,
            'unmatched_count' => 0,
            'week_count' => 0,
        ];

        foreach ($rows as $row) {
            $payload = $this->toApiArray($row);
            $weeks[] = $payload;
            $totals['fulfillment_cents'] += (int) $payload['fulfillment_cents'];
            $totals['postage_cents'] += (int) $payload['postage_cents'];
            $totals['materials_cents'] += (int) $payload['materials_cents'];
            $totals['returns_cents'] += (int) $payload['returns_cents'];
            $totals['custom_work_cents'] += (int) $payload['custom_work_cents'];
            $totals['wholesale_cents'] += (int) $payload['wholesale_cents'];
            $totals['total_cents'] += (int) $payload['total_cents'];
            $totals['unmatched_count'] += (int) $payload['unmatched_count'];
            $totals['week_count']++;
        }

        return [
            'weeks' => $weeks,
            'totals' => $totals,
            'from' => $fromMonday->toDateString(),
            'to' => $toMonday->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(BillingWeekEarning $earning): array
    {
        return [
            'id' => $earning->id,
            'week_start' => $earning->week_start !== null ? $earning->week_start->toDateString() : null,
            'week_end' => $earning->week_end !== null ? $earning->week_end->toDateString() : null,
            'fulfillment_cents' => (int) $earning->fulfillment_cents,
            'postage_cents' => (int) $earning->postage_cents,
            'materials_cents' => (int) $earning->materials_cents,
            'returns_cents' => (int) $earning->returns_cents,
            'custom_work_cents' => (int) $earning->custom_work_cents,
            'wholesale_cents' => (int) $earning->wholesale_cents,
            'total_cents' => (int) $earning->total_cents,
            'matched_line_count' => (int) $earning->matched_line_count,
            'unmatched_count' => (int) $earning->unmatched_count,
            'status' => (string) $earning->status,
            'error_message' => $earning->error_message,
            'generated_at' => $earning->generated_at !== null
                ? $earning->generated_at->toIso8601String()
                : null,
            'generated_by_user_id' => $earning->generated_by_user_id,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function unmatchedPayload(BillingWeekEarning $earning): array
    {
        $earning->loadMissing(['unmatchedItems.clientAccount', 'unmatchedItems.invoice']);

        $out = [];
        foreach ($earning->unmatchedItems as $row) {
            $account = $row->clientAccount;
            $invoice = $row->invoice;
            $out[] = [
                'id' => $row->id,
                'client_account_id' => (int) $row->client_account_id,
                'company_name' => $account !== null ? (string) $account->company_name : '',
                'invoice_id' => $row->invoice_id,
                'invoice_number' => $invoice !== null ? (string) $invoice->invoice_number : '',
                'invoice_item_id' => $row->invoice_item_id,
                'category' => $row->category,
                'display_name' => $row->display_name,
                'quantity' => (float) $row->quantity,
                'billed_cents' => (int) $row->billed_cents,
                'reason' => $row->reason,
                'reason_label' => $row->reason === self::REASON_COST_MISSING
                    ? 'Cost missing'
                    : 'Fee not found',
            ];
        }

        return $out;
    }

    public function normalizeFeeName(string $name): string
    {
        $s = trim($name);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = str_ireplace(['×', '✕', 'ｘ'], 'x', $s);
        $s = preg_replace('/\s*[xX]\s*/u', 'x', $s) ?? $s;
        if (preg_match('/^box\s+/iu', $s)) {
            $s = preg_replace('/^box\s+/iu', '', $s) ?? $s;
        }
        $s = $this->aliasFeeName($s);

        return mb_strtolower(trim($s));
    }

    private function aliasFeeName(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $aliases = [
            'fulfillment (first pick)' => 'First Pick',
            'fulfillment (additional pick)' => 'Additional Picks',
            'fulfillment (additional picks)' => 'Additional Picks',
            'first pick charge' => 'First Pick',
            'additional pick' => 'Additional Picks',
            'additional picks' => 'Additional Picks',
        ];

        return $aliases[$lower] ?? $name;
    }

    private function categoryToSnapshotField(string $category): ?string
    {
        $map = [
            InvoiceLineCategory::FULFILLMENT => 'fulfillment_cents',
            InvoiceLineCategory::POSTAGE => 'postage_cents',
            InvoiceLineCategory::PACKAGING => 'materials_cents',
            InvoiceLineCategory::RETURNS => 'returns_cents',
            InvoiceLineCategory::AD_HOC => 'custom_work_cents',
            InvoiceLineCategory::WHOLESALE => 'wholesale_cents',
        ];

        return $map[$category] ?? null;
    }

    /**
     * @return list<string>
     */
    private function feeGroupsForInvoiceCategory(string $category): array
    {
        if ($category === InvoiceLineCategory::AD_HOC) {
            return [PricingFeeTemplate::CATEGORY_CUSTOM_WORK, 'ad_hoc', ClientAccountFee::GROUP_CUSTOM_WORK];
        }
        if ($category === InvoiceLineCategory::PACKAGING) {
            return [PricingFeeTemplate::CATEGORY_PACKAGING];
        }

        return [$category];
    }

    /**
     * @return array{by_norm: array<string, array{cost: float|null, label: string}>, by_slug: array<string, array{cost: float|null, label: string}>}
     */
    private function buildFeeLookup(int $clientAccountId): array
    {
        $fees = ClientAccountFee::query()
            ->with('pricingTemplate')
            ->where('client_account_id', $clientAccountId)
            ->get();

        $byNorm = [];
        $bySlug = [];
        foreach ($fees as $fee) {
            $group = strtolower(trim((string) $fee->fee_group));
            $label = trim((string) ($fee->label ?? ''));
            if ($label === '' && $fee->pricingTemplate) {
                $label = trim((string) $fee->pricingTemplate->name);
            }
            if ($label === '') {
                continue;
            }
            $cost = $this->effectiveCost($fee);
            $entry = ['cost' => $cost, 'label' => $label, 'fee_group' => $group];
            $norm = $this->normalizeFeeName($label);
            $key = $group.'|'.$norm;
            $byNorm[$key] = $entry;
            $slug = Str::slug($label);
            if ($slug !== '') {
                $bySlug[$group.'|'.$slug] = $entry;
            }
        }

        return ['by_norm' => $byNorm, 'by_slug' => $bySlug];
    }

    /**
     * @param  array{by_norm: array<string, array{cost: float|null, label: string}>, by_slug: array<string, array{cost: float|null, label: string}>}  $lookup
     * @return array{status: string, cost?: float, reason?: string}
     */
    private function matchFee(array $lookup, string $invoiceCategory, string $displayName, string $groupKey): array
    {
        $groups = $this->feeGroupsForInvoiceCategory($invoiceCategory);
        $norm = $this->normalizeFeeName($displayName);
        $slugFromName = Str::slug($displayName);
        $slugFromKey = '';
        if (strpos($groupKey, ':') !== false) {
            $parts = explode(':', $groupKey, 2);
            $slugFromKey = trim((string) ($parts[1] ?? ''));
        }

        $entry = null;
        foreach ($groups as $group) {
            $group = strtolower(trim($group));
            $key = $group.'|'.$norm;
            if (isset($lookup['by_norm'][$key])) {
                $entry = $lookup['by_norm'][$key];
                break;
            }
            if ($slugFromName !== '' && isset($lookup['by_slug'][$group.'|'.$slugFromName])) {
                $entry = $lookup['by_slug'][$group.'|'.$slugFromName];
                break;
            }
            if ($slugFromKey !== '' && isset($lookup['by_slug'][$group.'|'.$slugFromKey])) {
                $entry = $lookup['by_slug'][$group.'|'.$slugFromKey];
                break;
            }
        }

        if ($entry === null) {
            return ['status' => 'unmatched', 'reason' => self::REASON_FEE_NOT_FOUND];
        }
        if ($entry['cost'] === null) {
            return ['status' => 'unmatched', 'reason' => self::REASON_COST_MISSING];
        }

        return ['status' => 'matched', 'cost' => (float) $entry['cost']];
    }

    /**
     * @return float|null
     */
    private function effectiveCost(ClientAccountFee $fee)
    {
        if ($fee->cost !== null && $fee->cost !== '') {
            return round((float) $fee->cost, 4);
        }
        if ($fee->relationLoaded('pricingTemplate') && $fee->pricingTemplate !== null
            && $fee->pricingTemplate->cost !== null && $fee->pricingTemplate->cost !== '') {
            return round((float) $fee->pricingTemplate->cost, 4);
        }

        return null;
    }
}
