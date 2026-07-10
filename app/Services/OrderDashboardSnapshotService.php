<?php

namespace App\Services;

use App\Jobs\RefreshOrderDashboardSectionJob;
use App\Jobs\RefreshPrimaryTotalsJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\OrderDashboardSection;
use App\Models\ShipHeroOrderQueueIndex;
use App\Support\ShipHeroCreditLimit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OrderDashboardSnapshotService
{
    private const ZOMBIE_MINUTES = 15;

    /** @var PortalQueueCountsService */
    private $queueCounts;

    /** @var ShipHeroOrderService */
    private $orders;

    /** @var ShipHeroOrderQueueIndexService */
    private $orderIndex;

    /** @var ShipHeroDashboardMetricsService */
    private $dashboardMetrics;

    public function __construct(
        PortalQueueCountsService $queueCounts,
        ShipHeroOrderService $orders,
        ShipHeroOrderQueueIndexService $orderIndex,
        ShipHeroDashboardMetricsService $dashboardMetrics
    ) {
        $this->queueCounts = $queueCounts;
        $this->orders = $orders;
        $this->orderIndex = $orderIndex;
        $this->dashboardMetrics = $dashboardMetrics;
    }

    public function bootstrapIfNeeded(): void
    {
        $this->ensureSectionRows();

        $rows = OrderDashboardSection::query()
            ->whereIn('section_key', OrderDashboardSection::ALL_KEYS)
            ->get()
            ->keyBy('section_key');

        $asn = $rows->get(OrderDashboardSection::KEY_ASN_PENDING);
        if (! $asn instanceof OrderDashboardSection || $asn->refreshed_at === null) {
            try {
                $this->refreshSection(OrderDashboardSection::KEY_ASN_PENDING);
            } catch (Throwable $e) {
                Log::warning('order_dashboard.asn_bootstrap_failed', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $rows = OrderDashboardSection::query()
            ->whereIn('section_key', OrderDashboardSection::SHIPHERO_KEYS)
            ->get()
            ->keyBy('section_key');

        $needsPrimaryRefresh = false;

        foreach (OrderDashboardSection::SHIPHERO_KEYS as $key) {
            $row = $rows->get($key);
            if (! $row instanceof OrderDashboardSection) {
                continue;
            }
            $row = $this->recoverZombieSection($row);
            if ($row->status === OrderDashboardSection::STATUS_RUNNING) {
                continue;
            }
            if ($row->refreshed_at === null || $row->status === OrderDashboardSection::STATUS_FAILED) {
                $needsPrimaryRefresh = true;

                continue;
            }
            if (in_array($key, [
                OrderDashboardSection::KEY_READY_TO_SHIP,
                OrderDashboardSection::KEY_SHIPPED,
            ], true)
                && (int) $row->total_count === 0
                && $row->refreshed_at->lessThan(now()->subMinutes(30))) {
                $needsPrimaryRefresh = true;
            }
        }

        if ($needsPrimaryRefresh) {
            $this->dispatchPrimaryTotalsRefresh();
        }
    }

    public function dispatchPrimaryTotalsRefresh(): void
    {
        $lockKey = 'orders:primary_totals_refresh_queued';
        if (Cache::has($lockKey)) {
            return;
        }

        Cache::put($lockKey, now()->toIso8601String(), now()->addMinutes(20));

        RefreshPrimaryTotalsJob::dispatch();
    }

    public function dispatchSectionRefresh(string $sectionKey): void
    {
        $this->validateSectionKey($sectionKey);
        $this->markSectionRunning($sectionKey);
        RefreshOrderDashboardSectionJob::dispatch($sectionKey);
    }

    public function recoverZombieSection(OrderDashboardSection $row): OrderDashboardSection
    {
        if ($row->status !== OrderDashboardSection::STATUS_RUNNING) {
            return $row;
        }

        $startedAt = $row->refresh_started_at ?? $row->updated_at;
        if ($startedAt === null) {
            return $row;
        }

        if ($startedAt->greaterThan(now()->subMinutes(self::ZOMBIE_MINUTES))) {
            return $row;
        }

        $this->markSectionFailed(
            $row->section_key,
            'Refresh did not finish in time. Retrying.'
        );

        return $row->fresh() ?? $row;
    }

    public function getDashboardPayload(): array
    {
        $this->ensureSectionRows();

        $rows = OrderDashboardSection::query()
            ->whereIn('section_key', OrderDashboardSection::ALL_KEYS)
            ->get()
            ->keyBy('section_key');

        foreach (OrderDashboardSection::ALL_KEYS as $key) {
            $row = $rows->get($key);
            if ($row instanceof OrderDashboardSection) {
                $rows->put($key, $this->recoverZombieSection($row));
            }
        }

        $sections = [];
        foreach (OrderDashboardSection::ALL_KEYS as $key) {
            $row = $rows->get($key);
            $sections[$key] = $this->serializeSection($row instanceof OrderDashboardSection ? $row : null, $key);
        }

        $sections = $this->overlaySectionsFromIndexWhenHealthy($sections);

        $rtsTotal = $this->resolveMetricTotal(
            OrderDashboardSection::KEY_READY_TO_SHIP,
            (int) ($sections[OrderDashboardSection::KEY_READY_TO_SHIP]['total_count'] ?? 0),
            $this->metricPayloadFromSection($rows->get(OrderDashboardSection::KEY_READY_TO_SHIP))
        );
        $shippedTotal = $this->resolveMetricTotal(
            OrderDashboardSection::KEY_SHIPPED,
            (int) ($sections[OrderDashboardSection::KEY_SHIPPED]['total_count'] ?? 0),
            $this->metricPayloadFromSection($rows->get(OrderDashboardSection::KEY_SHIPPED))
        );
        $onHoldTotal = $this->resolveOnHoldTotal();

        $rtsTotal = max($rtsTotal, $this->lastGoodTotal('ready_to_ship'));
        $shippedTotal = max($shippedTotal, $this->lastGoodTotal('shipped'));
        $onHoldTotal = max($onHoldTotal, $this->lastGoodTotal('on_hold'));

        $sections[OrderDashboardSection::KEY_READY_TO_SHIP]['total_count'] = $rtsTotal;
        $sections[OrderDashboardSection::KEY_SHIPPED]['total_count'] = $shippedTotal;

        if (isset($sections[OrderDashboardSection::KEY_HOLD_BACKORDER])) {
            $backorder = (int) ($sections[OrderDashboardSection::KEY_HOLD_BACKORDER]['total_count'] ?? 0);
            $sections[OrderDashboardSection::KEY_HOLD_BACKORDER]['total_count'] = max(
                $backorder,
                $this->lastGoodTotal('hold_backorder')
            );
        }

        return [
            'totals' => [
                'ready_to_ship' => $rtsTotal,
                'on_hold' => $onHoldTotal,
                'shipped' => $shippedTotal,
                'asn_pending' => (int) ($sections[OrderDashboardSection::KEY_ASN_PENDING]['total_count'] ?? 0),
            ],
            'sections' => $sections,
            'revision' => $this->getDashboardRevision(),
            'metrics_truncated' => (bool) (
                ($sections[OrderDashboardSection::KEY_READY_TO_SHIP]['truncated'] ?? false)
                || ($sections[OrderDashboardSection::KEY_SHIPPED]['truncated'] ?? false)
            ),
        ];
    }

    /**
     * Refresh RTS, shipped, and on-hold totals into snapshots.
     *
     * @param  bool  $live  When false, reads local index only (fast, no API credits). Default is live ShipHero.
     */
    public function refreshPrimaryTotals(bool $live = true): void
    {
        if ($live) {
            $this->refreshPrimaryTotalsLive();

            return;
        }

        $this->refreshPrimaryTotalsFromIndex();
    }

    /**
     * Fast path — aggregate from shiphero_order_queue_index (no ShipHero API credits).
     */
    public function refreshPrimaryTotalsFromIndex(): void
    {
        $this->clearStalePrimaryRunning();

        $startedAt = microtime(true);

        foreach ([
            OrderDashboardSection::KEY_READY_TO_SHIP,
            OrderDashboardSection::KEY_SHIPPED,
        ] as $sectionKey) {
            $sectionStarted = microtime(true);
            $result = $this->orderIndex->aggregateDashboardSection($sectionKey, false);
            $this->saveSectionPayload(
                $sectionKey,
                is_array($result['payload'] ?? null) ? $result['payload'] : ['accounts' => [], 'truncated' => false],
                (int) ($result['total_count'] ?? 0),
                (int) round((microtime(true) - $sectionStarted) * 1000)
            );
        }

        $onHoldTotal = $this->orderIndex->aggregateOnHoldTodayFromIndex();
        $this->dashboardMetrics->putOnHoldTotalCache($onHoldTotal);
        $this->rememberLastGoodTotal('on_hold', $onHoldTotal);

        foreach (OrderDashboardSection::HOLD_KEYS as $key) {
            try {
                $this->refreshSectionFromIndex($key);
            } catch (Throwable $e) {
                Log::warning('order_dashboard.hold_section_index_refresh_failed', [
                    'section_key' => $key,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->bumpDashboardRevision();
        Cache::forget('orders:primary_totals_refresh_queued');

        Log::info('order_dashboard.primary_totals_refreshed_from_index', [
            'ready_to_ship' => (int) OrderDashboardSection::query()
                ->where('section_key', OrderDashboardSection::KEY_READY_TO_SHIP)
                ->value('total_count'),
            'shipped' => (int) OrderDashboardSection::query()
                ->where('section_key', OrderDashboardSection::KEY_SHIPPED)
                ->value('total_count'),
            'on_hold_index' => $onHoldTotal,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /**
     * Live ShipHero API refresh — one account at a time with credit pacing (~3–5 min for 64 accounts).
     */
    public function refreshPrimaryTotalsLive(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $this->clearStalePrimaryRunning();

        $this->dashboardMetrics->clearCacheForToday();

        foreach (array_merge([
            OrderDashboardSection::KEY_READY_TO_SHIP,
            OrderDashboardSection::KEY_SHIPPED,
        ], OrderDashboardSection::HOLD_KEYS) as $sectionKey) {
            $this->resetSectionAccountBreakdown($sectionKey);
        }

        $startedAt = microtime(true);
        $accounts = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'status', 'shiphero_customer_account_id']);

        $onHoldTotal = 0;
        $failures = 0;

        foreach ($accounts as $index => $account) {
            if ($index > 0) {
                usleep(ShipHeroCreditLimit::INTER_ACCOUNT_SLEEP_MICROS);
            }

            $customerId = trim((string) $account->shiphero_customer_account_id);
            if ($customerId === '') {
                continue;
            }

            try {
                $rtsContext = $this->queueCounts->contextForDashboardSection(
                    $account,
                    OrderDashboardSection::KEY_READY_TO_SHIP
                );
                $rts = ShipHeroCreditLimit::run(function () use ($rtsContext, $customerId) {
                    return $this->orders->countOrders([
                        'customer_account_id' => $customerId,
                        'tab' => 'awaiting',
                        'order_date_from' => $this->isoDateOnly($rtsContext['awaiting_from'] ?? null),
                        'order_date_to' => $this->isoDateOnly($rtsContext['awaiting_to'] ?? null),
                        'timezone' => $rtsContext['timezone'] ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
                        'max_pages' => 50,
                    ]);
                });
                $this->mergeAccountIntoSection(
                    OrderDashboardSection::KEY_READY_TO_SHIP,
                    $account,
                    (int) ($rts['count'] ?? 0)
                );
            } catch (Throwable $e) {
                $failures++;
                Log::warning('order_dashboard.live_rts_account_failed', [
                    'client_account_id' => (int) $account->id,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $shippedContext = $this->queueCounts->contextForDashboardSection(
                    $account,
                    OrderDashboardSection::KEY_SHIPPED
                );
                $shipped = ShipHeroCreditLimit::run(function () use ($shippedContext, $customerId) {
                    return $this->orders->countShipments([
                        'customer_account_id' => $customerId,
                        'date_from' => $shippedContext['shipped_from'],
                        'date_to' => $shippedContext['shipped_to'],
                        'timezone' => $shippedContext['timezone'] ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
                        'max_pages' => 200,
                    ]);
                });
                $this->mergeAccountIntoSection(
                    OrderDashboardSection::KEY_SHIPPED,
                    $account,
                    (int) ($shipped['count'] ?? 0)
                );
            } catch (Throwable $e) {
                $failures++;
                Log::warning('order_dashboard.live_shipped_account_failed', [
                    'client_account_id' => (int) $account->id,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $holdContext = $this->queueCounts->contextForOnHoldDashboardTotal($account);
                $hold = ShipHeroCreditLimit::run(function () use ($holdContext, $customerId) {
                    return $this->orders->countOrders([
                        'customer_account_id' => $customerId,
                        'tab' => 'on_hold',
                        'order_date_from' => $this->isoDateOnly($holdContext['open_from'] ?? null),
                        'order_date_to' => $this->isoDateOnly($holdContext['open_to'] ?? null),
                        'timezone' => $holdContext['timezone'] ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
                        'max_pages' => 50,
                    ]);
                });
                $onHoldTotal += (int) ($hold['count'] ?? 0);
            } catch (Throwable $e) {
                $failures++;
                Log::warning('order_dashboard.live_on_hold_account_failed', [
                    'client_account_id' => (int) $account->id,
                    'message' => $e->getMessage(),
                ]);
            }

            foreach (OrderDashboardSection::HOLD_KEYS as $holdSectionKey) {
                try {
                    $holdSectionContext = $this->queueCounts->contextForDashboardSection($account, $holdSectionKey);
                    $holdSectionCount = ShipHeroCreditLimit::run(function () use ($holdSectionKey, $holdSectionContext) {
                        return $this->countForSection($holdSectionKey, $holdSectionContext);
                    });
                    $this->mergeAccountIntoSection(
                        $holdSectionKey,
                        $account,
                        (int) ($holdSectionCount['count'] ?? 0)
                    );
                } catch (Throwable $e) {
                    $failures++;
                    Log::warning('order_dashboard.live_hold_section_account_failed', [
                        'section_key' => $holdSectionKey,
                        'client_account_id' => (int) $account->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            if ($index > 0 && $index % 10 === 0) {
                $this->bumpDashboardRevision();
            }
        }

        $this->dashboardMetrics->putOnHoldTotalCache($onHoldTotal);

        $this->bumpDashboardRevision();
        Cache::forget('orders:primary_totals_refresh_queued');

        $rtsTotal = (int) OrderDashboardSection::query()
            ->where('section_key', OrderDashboardSection::KEY_READY_TO_SHIP)
            ->value('total_count');
        $shippedTotal = (int) OrderDashboardSection::query()
            ->where('section_key', OrderDashboardSection::KEY_SHIPPED)
            ->value('total_count');

        Log::info('order_dashboard.primary_totals_refreshed_live', [
            'ready_to_ship' => $rtsTotal,
            'shipped' => $shippedTotal,
            'on_hold' => $onHoldTotal,
            'accounts_failed' => $failures,
            'accounts_total' => $accounts->count(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $this->rememberLastGoodTotal('ready_to_ship', $rtsTotal);
        $this->rememberLastGoodTotal('shipped', $shippedTotal);
        $this->rememberLastGoodTotal('on_hold', $onHoldTotal);
    }

    private function resetSectionAccountBreakdown(string $sectionKey): void
    {
        $this->saveSectionPayload(
            $sectionKey,
            ['accounts' => [], 'truncated' => false],
            0,
            0,
            true
        );
    }

    public function clearStalePrimaryRunning(): void
    {
        OrderDashboardSection::query()
            ->whereIn('section_key', [
                OrderDashboardSection::KEY_READY_TO_SHIP,
                OrderDashboardSection::KEY_SHIPPED,
            ])
            ->where('status', OrderDashboardSection::STATUS_RUNNING)
            ->update([
                'status' => OrderDashboardSection::STATUS_IDLE,
                'refresh_started_at' => null,
                'error_message' => null,
            ]);
    }

    /**
     * @param  array<string, mixed>  $metricPayload
     */
    private function resolveMetricTotal(string $sectionKey, int $snapshotTotal, array $metricPayload = []): int
    {
        unset($sectionKey, $metricPayload);

        return max(0, $snapshotTotal);
    }

    private function indexAggregateTotal(string $sectionKey): ?int
    {
        if (! $this->orderIndex->indexHasRowsForSection($sectionKey)) {
            return null;
        }

        try {
            $fromIndex = $this->orderIndex->aggregateDashboardSection($sectionKey, false);

            return (int) ($fromIndex['total_count'] ?? 0);
        } catch (Throwable $e) {
            Log::warning('order_dashboard.index_fallback_failed', [
                'section_key' => $sectionKey,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $metricPayload
     */
    private function snapshotLooksPartial(array $metricPayload): bool
    {
        if ((bool) ($metricPayload['truncated'] ?? false)) {
            return true;
        }

        $failures = (int) ($metricPayload['accounts_failed'] ?? 0);
        $accountsTotal = (int) ($metricPayload['accounts_total'] ?? 0);

        return $accountsTotal > 0 && $failures > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function metricPayloadFromSection(?OrderDashboardSection $row): array
    {
        if (! $row instanceof OrderDashboardSection) {
            return [];
        }

        $payload = is_array($row->payload) ? $row->payload : [];

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array{payload: array<string, mixed>, total_count: int}  $result
     */
    private function saveMetricSectionPayload(string $sectionKey, array $result, int $durationMs): void
    {
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $total = (int) ($result['total_count'] ?? 0);
        $failures = (int) ($payload['accounts_failed'] ?? 0);
        $accountsTotal = (int) ($payload['accounts_total'] ?? 0);

        $row = OrderDashboardSection::query()->where('section_key', $sectionKey)->first();
        $previous = $row instanceof OrderDashboardSection ? (int) $row->total_count : 0;

        if ($total === 0 && $previous > 0 && $accountsTotal > 0 && $failures >= $accountsTotal) {
            Log::warning('order_dashboard.skip_zero_overwrite', [
                'section_key' => $sectionKey,
                'previous_total' => $previous,
                'accounts_failed' => $failures,
                'accounts_total' => $accountsTotal,
            ]);

            return;
        }

        $this->saveSectionPayload($sectionKey, $payload, $total, $durationMs);
    }

    private function resolveOnHoldTotal(): int
    {
        return max(0, $this->dashboardMetrics->cachedOnHoldTotal());
    }

    public function getDashboardRevision(): int
    {
        return max(0, (int) Cache::get($this->dashboardRevisionKey(), 0));
    }

    public function bumpDashboardRevision(): int
    {
        $key = $this->dashboardRevisionKey();
        if (! Cache::has($key)) {
            Cache::put($key, 1, now()->addDays(30));

            return 1;
        }

        $next = (int) Cache::increment($key);
        Cache::put($key, $next, now()->addDays(30));

        return $next;
    }

    private function dashboardRevisionKey(): string
    {
        return 'orders:home_dashboard:revision';
    }

    public function markSectionRunning(string $sectionKey): void
    {
        $this->validateSectionKey($sectionKey);
        $this->ensureSectionRows();

        OrderDashboardSection::query()->where('section_key', $sectionKey)->update([
            'status' => OrderDashboardSection::STATUS_RUNNING,
            'refresh_started_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markSectionFailed(string $sectionKey, string $message): void
    {
        $this->validateSectionKey($sectionKey);
        $this->ensureSectionRows();

        OrderDashboardSection::query()->where('section_key', $sectionKey)->update([
            'status' => OrderDashboardSection::STATUS_FAILED,
            'refresh_started_at' => null,
            'error_message' => $message !== '' ? $message : 'Refresh failed.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveSectionPayload(string $sectionKey, array $payload, int $totalCount, int $durationMs, bool $allowDecrease = false): void
    {
        $this->validateSectionKey($sectionKey);
        $this->ensureSectionRows();

        $row = OrderDashboardSection::query()->where('section_key', $sectionKey)->first();
        $previous = $row instanceof OrderDashboardSection ? (int) $row->total_count : 0;
        $totalCount = max(0, $totalCount);

        if (! $allowDecrease && $previous > 0 && $totalCount < $previous) {
            Log::info('order_dashboard.skip_lower_total', [
                'section_key' => $sectionKey,
                'previous_total' => $previous,
                'new_total' => $totalCount,
            ]);

            return;
        }

        OrderDashboardSection::query()->where('section_key', $sectionKey)->update([
            'payload' => $payload,
            'total_count' => $totalCount,
            'status' => OrderDashboardSection::STATUS_IDLE,
            'refreshed_at' => now(),
            'refresh_started_at' => null,
            'error_message' => null,
            'duration_ms' => max(0, $durationMs),
        ]);

        $this->rememberLastGoodForSection($sectionKey, $totalCount);
    }

    private function rememberLastGoodForSection(string $sectionKey, int $totalCount): void
    {
        switch ($sectionKey) {
            case OrderDashboardSection::KEY_READY_TO_SHIP:
                $this->rememberLastGoodTotal('ready_to_ship', $totalCount);
                break;
            case OrderDashboardSection::KEY_SHIPPED:
                $this->rememberLastGoodTotal('shipped', $totalCount);
                break;
            case OrderDashboardSection::KEY_HOLD_BACKORDER:
                $this->rememberLastGoodTotal('hold_backorder', $totalCount);
                break;
        }
    }

    private function rememberLastGoodTotal(string $metric, int $totalCount): void
    {
        if ($totalCount <= 0) {
            return;
        }

        $key = $this->lastGoodTotalKey($metric);
        $previous = (int) Cache::get($key, 0);
        if ($totalCount >= $previous) {
            Cache::put($key, $totalCount, now()->addDays(7));
        }
    }

    private function lastGoodTotal(string $metric): int
    {
        return max(0, (int) Cache::get($this->lastGoodTotalKey($metric), 0));
    }

    private function lastGoodTotalKey(string $metric): string
    {
        $today = Carbon::now(PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE)->toDateString();

        return sprintf('orders:dashboard:last_good:%s:%s', $metric, $today);
    }

    public function refreshSection(string $sectionKey, bool $useIndexOnly = false): void
    {
        $this->validateSectionKey($sectionKey);

        if ($sectionKey !== OrderDashboardSection::KEY_ASN_PENDING && $useIndexOnly) {
            $this->refreshSectionFromIndex($sectionKey);

            return;
        }

        $startedAt = microtime(true);

        $this->markSectionRunning($sectionKey);

        try {
            $result = $this->buildSectionPayload($sectionKey, true, $useIndexOnly);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->saveSectionPayload(
                $sectionKey,
                $result['payload'],
                (int) $result['total_count'],
                $durationMs
            );

            if (in_array($sectionKey, [
                OrderDashboardSection::KEY_READY_TO_SHIP,
                OrderDashboardSection::KEY_SHIPPED,
            ], true)) {
                $this->dashboardMetrics->clearCacheForToday();
            }

            Log::info('order_dashboard.section_refreshed', [
                'section_key' => $sectionKey,
                'total_count' => (int) $result['total_count'],
                'accounts' => count($result['payload']['accounts'] ?? []),
                'truncated' => (bool) ($result['payload']['truncated'] ?? false),
                'duration_ms' => $durationMs,
            ]);
        } catch (Throwable $e) {
            $this->markSectionFailed(
                $sectionKey,
                $e->getMessage() !== '' ? $e->getMessage() : 'Refresh failed.'
            );
            throw $e;
        }
    }

    /**
     * Fast refresh for HTTP requests — reads the local index only (no ShipHero sync/API).
     */
    public function refreshSectionFromIndex(string $sectionKey): void
    {
        $this->validateSectionKey($sectionKey);
        $startedAt = microtime(true);

        try {
            $result = $this->buildSectionPayload($sectionKey, false);
            $needsBackgroundSync = $sectionKey !== OrderDashboardSection::KEY_ASN_PENDING
                && ! $this->orderIndex->indexHasRowsForSection($sectionKey);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->saveSectionPayload(
                $sectionKey,
                $result['payload'],
                (int) $result['total_count'],
                $durationMs
            );

            if ($needsBackgroundSync) {
                $this->dispatchSectionRefresh($sectionKey);
            }

            Log::info('order_dashboard.section_refreshed_from_index', [
                'section_key' => $sectionKey,
                'total_count' => (int) $result['total_count'],
                'accounts' => count($result['payload']['accounts'] ?? []),
                'duration_ms' => $durationMs,
                'background_sync_queued' => $needsBackgroundSync,
            ]);
        } catch (Throwable $e) {
            $this->markSectionFailed(
                $sectionKey,
                $e->getMessage() !== '' ? $e->getMessage() : 'Refresh failed.'
            );
            throw $e;
        }
    }

    /**
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    private function buildSectionPayload(string $sectionKey, bool $allowRemoteFallback, bool $useIndexOnly = false): array
    {
        if ($sectionKey === OrderDashboardSection::KEY_ASN_PENDING) {
            return $this->buildAsnPendingPayload();
        }

        if ($allowRemoteFallback && ! $useIndexOnly) {
            if ($sectionKey === OrderDashboardSection::KEY_READY_TO_SHIP) {
                return $this->dashboardMetrics->aggregateReadyToShip(false);
            }

            if ($sectionKey === OrderDashboardSection::KEY_SHIPPED) {
                return $this->dashboardMetrics->aggregateShippedToday(false);
            }

            return $this->buildShipHeroSectionPayload($sectionKey, true);
        }

        if ($this->orderIndex->indexIsHealthyForSection($sectionKey)) {
            return $this->orderIndex->aggregateDashboardSection($sectionKey, false);
        }

        if (! $allowRemoteFallback) {
            return [
                'payload' => ['accounts' => [], 'truncated' => false],
                'total_count' => 0,
            ];
        }

        $this->syncIndexForDashboardSection($sectionKey);
        if ($this->orderIndex->indexIsHealthyForSection($sectionKey)) {
            return $this->orderIndex->aggregateDashboardSection($sectionKey, false);
        }

        return $this->buildShipHeroSectionPayload($sectionKey, true);
    }

    /**
     * @param  list<string>  $sectionKeys
     */
    public function refreshSections(array $sectionKeys): void
    {
        foreach ($sectionKeys as $key) {
            $this->refreshSection((string) $key);
        }
    }

    public function patchAccountFromQueueTab(int $clientAccountId, string $queueTab): void
    {
        if ($clientAccountId <= 0) {
            return;
        }

        $account = ClientAccount::query()->find($clientAccountId);
        if ($account === null) {
            return;
        }

        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            return;
        }

        foreach ($this->sectionKeysForQueueTab($queueTab) as $sectionKey) {
            $context = $this->queueCounts->contextForDashboardSection($account, $sectionKey);
            $count = $this->orderIndex->countForDashboardSection($clientAccountId, $sectionKey, $context);
            $this->mergeAccountIntoSection($sectionKey, $account, $count);
        }
    }

    public function patchAccountAsnPending(int $clientAccountId): void
    {
        if ($clientAccountId <= 0) {
            return;
        }

        $account = ClientAccount::query()->find($clientAccountId);
        if ($account === null) {
            return;
        }

        $count = (int) ClientAccountAsn::query()
            ->where('client_account_id', $clientAccountId)
            ->where('status', ClientAccountAsn::STATUS_PENDING)
            ->count();

        $this->mergeAccountIntoSection(OrderDashboardSection::KEY_ASN_PENDING, $account, $count);
    }

    private function mergeAccountIntoSection(string $sectionKey, ClientAccount $account, int $count): void
    {
        $this->validateSectionKey($sectionKey);
        $this->ensureSectionRows();

        $row = OrderDashboardSection::query()->where('section_key', $sectionKey)->first();
        if (! $row instanceof OrderDashboardSection) {
            return;
        }

        if ($row->status === OrderDashboardSection::STATUS_RUNNING) {
            return;
        }

        $payload = is_array($row->payload) ? $row->payload : [];
        $accounts = isset($payload['accounts']) && is_array($payload['accounts']) ? $payload['accounts'] : [];
        $accountId = (int) $account->id;

        $accounts = array_values(array_filter($accounts, static function ($entry) use ($accountId) {
            return is_array($entry) && (int) ($entry['account_id'] ?? 0) !== $accountId;
        }));

        if ($count > 0) {
            $accounts[] = [
                'account_id' => $accountId,
                'account_name' => (string) $account->company_name,
                'account_status' => (string) $account->status,
                'orders_count' => $count,
            ];
        }

        usort($accounts, static function (array $a, array $b) {
            return ($b['orders_count'] ?? 0) <=> ($a['orders_count'] ?? 0);
        });

        $total = 0;
        foreach ($accounts as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $total += (int) ($entry['orders_count'] ?? 0);
        }

        $payload['accounts'] = $accounts;

        OrderDashboardSection::query()->where('section_key', $sectionKey)->update([
            'payload' => $payload,
            'total_count' => max(0, $total),
            'status' => OrderDashboardSection::STATUS_IDLE,
            'refreshed_at' => now(),
            'error_message' => null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function sectionKeysForQueueTab(string $queueTab): array
    {
        $tab = strtolower(trim($queueTab));

        switch ($tab) {
            case ShipHeroOrderQueueIndex::KIND_AWAITING:
            case 'awaiting':
                return [OrderDashboardSection::KEY_READY_TO_SHIP];
            case ShipHeroOrderQueueIndex::KIND_SHIPPED:
            case 'shipped':
                return [OrderDashboardSection::KEY_SHIPPED];
            case ShipHeroOrderQueueIndex::KIND_BACKORDER:
            case 'backorder':
                return [OrderDashboardSection::KEY_HOLD_BACKORDER];
            case ShipHeroOrderQueueIndex::KIND_ON_HOLD:
            case 'on_hold':
                return [
                    OrderDashboardSection::KEY_HOLD_OPERATOR,
                    OrderDashboardSection::KEY_HOLD_ADDRESS,
                    OrderDashboardSection::KEY_HOLD_FRAUD,
                    OrderDashboardSection::KEY_HOLD_PAYMENT,
                    OrderDashboardSection::KEY_HOLD_USER,
                ];
            default:
                return [];
        }
    }

    /**
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    private function buildAsnPendingPayload(): array
    {
        $counts = ClientAccountAsn::query()
            ->selectRaw('client_account_id, COUNT(*) as aggregate')
            ->where('status', ClientAccountAsn::STATUS_PENDING)
            ->groupBy('client_account_id')
            ->pluck('aggregate', 'client_account_id');

        if ($counts->isEmpty()) {
            return [
                'payload' => ['accounts' => [], 'truncated' => false],
                'total_count' => 0,
            ];
        }

        $accountIds = $counts->keys()->map(static function ($id) {
            return (int) $id;
        })->all();

        $accounts = ClientAccount::query()
            ->whereIn('id', $accountIds)
            ->get(['id', 'company_name', 'status'])
            ->keyBy('id');

        $rows = [];
        $total = 0;
        foreach ($counts as $accountId => $count) {
            $id = (int) $accountId;
            $c = (int) $count;
            if ($c <= 0) {
                continue;
            }
            $account = $accounts->get($id);
            $rows[] = [
                'account_id' => $id,
                'account_name' => $account ? (string) $account->company_name : 'Account #'.$id,
                'account_status' => $account ? (string) $account->status : '',
                'orders_count' => $c,
            ];
            $total += $c;
        }

        usort($rows, static function (array $a, array $b) {
            return ($b['orders_count'] ?? 0) <=> ($a['orders_count'] ?? 0);
        });

        return [
            'payload' => ['accounts' => $rows, 'truncated' => false],
            'total_count' => $total,
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    private function buildShipHeroSectionPayload(string $sectionKey, bool $preferRemoteApi = false): array
    {
        if (! $preferRemoteApi && $this->orderIndex->indexHasRowsForSection($sectionKey)) {
            return $this->orderIndex->aggregateDashboardSection($sectionKey);
        }

        $accounts = $this->shipHeroLinkedAccounts();
        if ($accounts === []) {
            return [
                'payload' => ['accounts' => [], 'truncated' => false],
                'total_count' => 0,
            ];
        }

        if ($sectionKey === OrderDashboardSection::KEY_READY_TO_SHIP) {
            return $this->buildReadyToShipPayload($accounts);
        }

        $rows = [];
        $total = 0;
        $truncated = false;

        foreach ($accounts as $account) {
            $context = $this->queueCounts->contextForDashboardSection($account, $sectionKey);
            try {
                $countResult = $this->countForSection($sectionKey, $context);
            } catch (Throwable $e) {
                Log::warning('order_dashboard.live_section_count_failed', [
                    'section_key' => $sectionKey,
                    'client_account_id' => (int) $account->id,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }
            $count = (int) ($countResult['count'] ?? 0);
            $truncated = $truncated || (bool) ($countResult['truncated'] ?? false);
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
            'payload' => ['accounts' => $rows, 'truncated' => $truncated],
            'total_count' => $total,
        ];
    }

    /**
     * @param  list<ClientAccount>  $accounts
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    private function buildReadyToShipPayload(array $accounts): array
    {
        $mapped = [];
        foreach ($accounts as $account) {
            $customerId = trim((string) $account->shiphero_customer_account_id);
            if ($customerId === '') {
                continue;
            }
            $context = $this->queueCounts->contextForAccount($account);
            $mapped[] = [
                'id' => (int) $account->id,
                'name' => (string) $account->company_name,
                'customer_account_id' => $customerId,
                'account_status' => (string) $account->status,
                'awaiting_from' => $context['awaiting_from'],
                'awaiting_to' => $context['awaiting_to'],
            ];
        }

        if ($mapped === []) {
            return [
                'payload' => ['accounts' => [], 'truncated' => false],
                'total_count' => 0,
            ];
        }

        $accountsOut = [];
        $total = 0;
        foreach ($mapped as $row) {
            $summary = $this->orders->readyToShipSummaryForAccounts(
                [
                    [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'customer_account_id' => (string) $row['customer_account_id'],
                    ],
                ],
                $this->isoDateOnly($row['awaiting_from'] ?? null),
                $this->isoDateOnly($row['awaiting_to'] ?? null)
            );
            $count = (int) ($summary['ready_to_ship_total'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $accountsOut[] = [
                'account_id' => (int) $row['id'],
                'account_name' => (string) $row['name'],
                'account_status' => (string) ($row['account_status'] ?? ''),
                'orders_count' => $count,
            ];
            $total += $count;
        }

        usort($accountsOut, static function (array $a, array $b) {
            return ($b['orders_count'] ?? 0) <=> ($a['orders_count'] ?? 0);
        });

        return [
            'payload' => [
                'accounts' => $accountsOut,
                'truncated' => false,
            ],
            'total_count' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{count: int, truncated: bool}
     */
    private function countForSection(string $sectionKey, array $context): array
    {
        if ($sectionKey === OrderDashboardSection::KEY_SHIPPED) {
            return $this->orders->countShipments([
                'customer_account_id' => $context['customer_id'],
                'date_from' => $context['shipped_from'],
                'date_to' => $context['shipped_to'],
                'timezone' => $context['timezone'] ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
                'max_pages' => 200,
            ]);
        }

        if ($sectionKey === OrderDashboardSection::KEY_HOLD_BACKORDER) {
            return $this->orders->countOrders([
                'customer_account_id' => $context['customer_id'],
                'tab' => 'backorder',
                'order_date_from' => $context['open_from'],
                'order_date_to' => $context['open_to'],
                'max_pages' => 50,
            ]);
        }

        $holdReason = $this->holdReasonForSection($sectionKey);
        if ($holdReason === null) {
            throw new RuntimeException('Unsupported hold section: '.$sectionKey);
        }

        return $this->orders->countHoldOrdersForAccount(
            (string) $context['customer_id'],
            $holdReason,
            $context['open_from'],
            $context['open_to']
        );
    }

    private function holdReasonForSection(string $sectionKey): ?string
    {
        switch ($sectionKey) {
            case OrderDashboardSection::KEY_HOLD_OPERATOR:
                return 'operator';
            case OrderDashboardSection::KEY_HOLD_ADDRESS:
                return 'address';
            case OrderDashboardSection::KEY_HOLD_FRAUD:
                return 'fraud';
            case OrderDashboardSection::KEY_HOLD_PAYMENT:
                return 'payment';
            case OrderDashboardSection::KEY_HOLD_USER:
                return 'user';
            default:
                return null;
        }
    }

    /**
     * @return list<ClientAccount>
     */
    private function shipHeroLinkedAccounts(): array
    {
        return ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('company_name')
            ->get()
            ->all();
    }

    private function ensureSectionRows(): void
    {
        $existing = OrderDashboardSection::query()
            ->whereIn('section_key', OrderDashboardSection::ALL_KEYS)
            ->pluck('section_key')
            ->all();

        $missing = array_diff(OrderDashboardSection::ALL_KEYS, $existing);
        if ($missing === []) {
            return;
        }

        $now = now();
        foreach ($missing as $key) {
            OrderDashboardSection::query()->insert([
                'section_key' => $key,
                'payload' => json_encode(['accounts' => [], 'truncated' => false]),
                'total_count' => 0,
                'status' => OrderDashboardSection::STATUS_IDLE,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Dashboard sections use live snapshot rows only (no index overlay on read).
     *
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<string, array<string, mixed>>
     */
    private function overlaySectionsFromIndexWhenHealthy(array $sections): array
    {
        return $sections;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSection(?OrderDashboardSection $row, string $fallbackKey): array
    {
        if (! $row instanceof OrderDashboardSection) {
            return [
                'section_key' => $fallbackKey,
                'total_count' => 0,
                'status' => OrderDashboardSection::STATUS_IDLE,
                'refreshed_at' => null,
                'refresh_started_at' => null,
                'error_message' => null,
                'duration_ms' => null,
                'accounts' => [],
                'truncated' => false,
            ];
        }

        $payload = is_array($row->payload) ? $row->payload : [];
        $accounts = isset($payload['accounts']) && is_array($payload['accounts']) ? $payload['accounts'] : [];

        return [
            'section_key' => (string) $row->section_key,
            'total_count' => (int) $row->total_count,
            'status' => (string) $row->status,
            'refreshed_at' => $row->refreshed_at !== null ? $row->refreshed_at->toIso8601String() : null,
            'refresh_started_at' => $row->refresh_started_at !== null ? $row->refresh_started_at->toIso8601String() : null,
            'error_message' => $row->error_message,
            'duration_ms' => $row->duration_ms !== null ? (int) $row->duration_ms : null,
            'accounts' => $accounts,
            'truncated' => (bool) ($payload['truncated'] ?? false),
        ];
    }

    private function validateSectionKey(string $sectionKey): void
    {
        if ($sectionKey === 'all') {
            return;
        }
        if (! in_array($sectionKey, OrderDashboardSection::ALL_KEYS, true)) {
            throw new RuntimeException('Invalid dashboard section: '.$sectionKey);
        }
    }

    private function isoDateOnly(?string $iso): ?string
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }
        try {
            return Carbon::parse($iso)->toDateString();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function syncIndexForDashboardSection(string $sectionKey): void
    {
        $tab = $this->queueTabForDashboardSection($sectionKey);
        if ($tab === null) {
            return;
        }

        foreach ($this->shipHeroLinkedAccounts() as $account) {
            try {
                $this->orderIndex->syncAccountQueue((int) $account->id, $tab);
            } catch (Throwable $e) {
                Log::warning('order_dashboard.index_sync_failed', [
                    'section_key' => $sectionKey,
                    'client_account_id' => (int) $account->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function queueTabForDashboardSection(string $sectionKey): ?string
    {
        switch ($sectionKey) {
            case OrderDashboardSection::KEY_READY_TO_SHIP:
                return ShipHeroOrderQueueIndex::KIND_AWAITING;
            case OrderDashboardSection::KEY_SHIPPED:
                return ShipHeroOrderQueueIndex::KIND_SHIPPED;
            case OrderDashboardSection::KEY_HOLD_BACKORDER:
                return ShipHeroOrderQueueIndex::KIND_BACKORDER;
            case OrderDashboardSection::KEY_HOLD_OPERATOR:
            case OrderDashboardSection::KEY_HOLD_ADDRESS:
            case OrderDashboardSection::KEY_HOLD_FRAUD:
            case OrderDashboardSection::KEY_HOLD_PAYMENT:
            case OrderDashboardSection::KEY_HOLD_USER:
                return ShipHeroOrderQueueIndex::KIND_ON_HOLD;
            default:
                return null;
        }
    }
}
