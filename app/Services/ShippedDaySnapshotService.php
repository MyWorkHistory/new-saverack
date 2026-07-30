<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\OrderDashboardSection;
use App\Models\ShippedDaySnapshot;
use App\Models\ShippedDaySnapshotAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ShippedDaySnapshotService
{
    public const TIMEZONE = PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE;

    /** @var PortalQueueCountsService */
    private $queueCounts;

    /** @var ShipHeroOrderQueueIndexService */
    private $orderIndex;

    /** @var OrderDashboardSnapshotService */
    private $dashboardSnapshots;

    public function __construct(
        PortalQueueCountsService $queueCounts,
        ShipHeroOrderQueueIndexService $orderIndex,
        OrderDashboardSnapshotService $dashboardSnapshots
    ) {
        $this->queueCounts = $queueCounts;
        $this->orderIndex = $orderIndex;
        $this->dashboardSnapshots = $dashboardSnapshots;
    }

    public function timezone(): string
    {
        return self::TIMEZONE;
    }

    public function nowNy(?Carbon $now = null): Carbon
    {
        return ($now ?? now())->copy()->timezone(self::TIMEZONE);
    }

    /**
     * Capture (or replace) the daily shipped snapshot for a NY calendar day.
     */
    public function captureDay(?Carbon $day = null): ShippedDaySnapshot
    {
        $ny = $this->nowNy($day);
        $dateString = $ny->toDateString();
        $aggregate = $this->aggregateShippedForDate($dateString);

        return DB::transaction(function () use ($dateString, $aggregate) {
            $snapshot = ShippedDaySnapshot::query()->updateOrCreate(
                ['snapshot_date' => $dateString],
                [
                    'total_count' => (int) $aggregate['total_count'],
                    'captured_at' => now(),
                    'timezone' => self::TIMEZONE,
                ]
            );

            $snapshot->accounts()->delete();

            foreach ($aggregate['accounts'] as $row) {
                $snapshot->accounts()->create([
                    'client_account_id' => (int) $row['account_id'],
                    'account_name' => (string) $row['account_name'],
                    'orders_count' => (int) $row['orders_count'],
                ]);
            }

            return $snapshot->fresh(['accounts']);
        });
    }

    /**
     * Per-account shipped label counts for a single NY calendar day (from local queue index).
     *
     * @return array{accounts: list<array<string, mixed>>, total_count: int}
     */
    public function aggregateShippedForDate(string $dateYmd): array
    {
        $accounts = ClientAccount::query()
            ->operationalForOrderDashboards()
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'status', 'shiphero_customer_account_id']);

        $rows = [];
        $total = 0;
        foreach ($accounts as $account) {
            $context = $this->queueCounts->contextForAccount($account, [
                'order_date_from' => $dateYmd,
                'order_date_to' => $dateYmd,
            ]);
            $count = $this->orderIndex->countShippedTodayFromIndex((int) $account->id, $context);
            if ($count <= 0) {
                continue;
            }
            $rows[] = [
                'account_id' => (int) $account->id,
                'account_name' => (string) $account->company_name,
                'account_status' => (string) $account->status,
                'orders_count' => $count,
            ];
            $total += $count;
        }

        usort($rows, static function (array $a, array $b) {
            return ($b['orders_count'] ?? 0) <=> ($a['orders_count'] ?? 0);
        });

        return [
            'accounts' => $rows,
            'total_count' => $total,
        ];
    }

    /**
     * Dashboard payload for admin Shipped page.
     *
     * @return array<string, mixed>
     */
    public function getDashboardPayload(): array
    {
        $now = $this->nowNy();
        $today = $now->toDateString();
        $yesterday = $now->copy()->subDay()->toDateString();

        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd = $now->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
        $lastWeekStart = $now->copy()->subWeek()->startOfWeek(Carbon::MONDAY)->toDateString();
        $lastWeekEnd = $now->copy()->subWeek()->endOfWeek(Carbon::SUNDAY)->toDateString();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();

        $todayPanel = $this->liveTodayPanel();
        $yesterdayPanel = $this->dayPanel($yesterday);

        $todayTotal = (int) $todayPanel['total_count'];
        $yesterdayTotal = (int) $yesterdayPanel['total_count'];

        return [
            'timezone' => self::TIMEZONE,
            'as_of' => $now->toIso8601String(),
            'dates' => [
                'today' => $today,
                'yesterday' => $yesterday,
                'this_week_start' => $weekStart,
                'this_week_end' => $weekEnd,
                'last_week_start' => $lastWeekStart,
                'last_week_end' => $lastWeekEnd,
                'this_month_start' => $monthStart,
                'this_month_end' => $monthEnd,
            ],
            'totals' => [
                'today' => $todayTotal,
                'yesterday' => $yesterdayTotal,
                'this_week' => $this->sumRange($weekStart, $weekEnd, $today, $todayTotal),
                'last_week' => $this->sumRange($lastWeekStart, $lastWeekEnd, $today, $todayTotal),
                'this_month' => $this->sumRange($monthStart, $monthEnd, $today, $todayTotal),
            ],
            'today' => $todayPanel,
            'yesterday' => $yesterdayPanel,
        ];
    }

    /**
     * Refresh live "shipped today" dashboard section, then return full shipped dashboard payload.
     *
     * @return array<string, mixed>
     */
    public function refreshToday(bool $fromIndex = true): array
    {
        if ($fromIndex) {
            $this->dashboardSnapshots->refreshSectionFromIndex(OrderDashboardSection::KEY_SHIPPED);
        } else {
            $this->dashboardSnapshots->refreshSection(OrderDashboardSection::KEY_SHIPPED);
        }

        return $this->getDashboardPayload();
    }

    /**
     * @return array<string, mixed>
     */
    private function liveTodayPanel(): array
    {
        $payload = $this->dashboardSnapshots->getDashboardPayload();
        $section = is_array($payload['sections'][OrderDashboardSection::KEY_SHIPPED] ?? null)
            ? $payload['sections'][OrderDashboardSection::KEY_SHIPPED]
            : [];

        $accounts = [];
        foreach ($section['accounts'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $accountId = (int) ($row['account_id'] ?? 0);
            $count = (int) ($row['orders_count'] ?? 0);
            if ($accountId <= 0 || $count <= 0) {
                continue;
            }
            $accounts[] = [
                'account_id' => $accountId,
                'account_name' => (string) ($row['account_name'] ?? ''),
                'account_status' => (string) ($row['account_status'] ?? ''),
                'orders_count' => $count,
            ];
        }

        return [
            'total_count' => (int) ($section['total_count'] ?? 0),
            'accounts' => $accounts,
            'refreshed_at' => $section['refreshed_at'] ?? null,
            'status' => (string) ($section['status'] ?? OrderDashboardSection::STATUS_IDLE),
            'from_snapshot' => false,
            'truncated' => (bool) ($section['truncated'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dayPanel(string $dateYmd): array
    {
        $snapshot = ShippedDaySnapshot::query()
            ->with(['accounts' => function ($q) {
                $q->orderByDesc('orders_count');
            }])
            ->whereDate('snapshot_date', $dateYmd)
            ->first();

        if ($snapshot !== null) {
            $accounts = [];
            foreach ($snapshot->accounts as $row) {
                $accounts[] = [
                    'account_id' => (int) $row->client_account_id,
                    'account_name' => (string) $row->account_name,
                    'account_status' => '',
                    'orders_count' => (int) $row->orders_count,
                ];
            }

            return [
                'total_count' => (int) $snapshot->total_count,
                'accounts' => $accounts,
                'refreshed_at' => $snapshot->captured_at !== null
                    ? $snapshot->captured_at->toIso8601String()
                    : null,
                'status' => OrderDashboardSection::STATUS_IDLE,
                'from_snapshot' => true,
                'truncated' => false,
            ];
        }

        $aggregate = $this->aggregateShippedForDate($dateYmd);

        return [
            'total_count' => (int) $aggregate['total_count'],
            'accounts' => $aggregate['accounts'],
            'refreshed_at' => null,
            'status' => OrderDashboardSection::STATUS_IDLE,
            'from_snapshot' => false,
            'truncated' => false,
        ];
    }

    /**
     * Sum shipped counts for an inclusive NY date range.
     * Uses snapshots when present; falls back to index for missing days (except open "today" which uses $todayLive).
     */
    private function sumRange(string $fromYmd, string $toYmd, string $todayYmd, int $todayLive): int
    {
        $from = Carbon::parse($fromYmd, self::TIMEZONE)->startOfDay();
        $to = Carbon::parse($toYmd, self::TIMEZONE)->startOfDay();
        if ($from->gt($to)) {
            return 0;
        }

        $snapshots = ShippedDaySnapshot::query()
            ->whereDate('snapshot_date', '>=', $fromYmd)
            ->whereDate('snapshot_date', '<=', $toYmd)
            ->get(['snapshot_date', 'total_count'])
            ->keyBy(function (ShippedDaySnapshot $row) {
                return $row->snapshot_date->toDateString();
            });

        $total = 0;
        $indexFallbackEarliest = $this->nowNy()->copy()->subDays(35)->startOfDay();

        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $ymd = $cursor->toDateString();
            if ($ymd === $todayYmd) {
                $total += $todayLive;
                continue;
            }
            $snap = $snapshots->get($ymd);
            if ($snap instanceof ShippedDaySnapshot) {
                $total += (int) $snap->total_count;
                continue;
            }
            // Only fall back to the rolling queue index for recent days; older gaps stay 0 until snapshotted.
            if ($cursor->lt($indexFallbackEarliest) || $cursor->gt($this->nowNy()->startOfDay())) {
                continue;
            }
            $total += (int) $this->aggregateShippedForDate($ymd)['total_count'];
        }

        return $total;
    }
}
