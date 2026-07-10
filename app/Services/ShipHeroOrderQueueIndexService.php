<?php

namespace App\Services;

use App\Jobs\PatchHomeDashboardAccountJob;
use App\Models\ClientAccount;
use App\Models\OrderDashboardSection;
use App\Models\ShipHeroOrderQueueIndex;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ShipHeroOrderQueueIndexService
{
    public const SYNC_STATUS_IDLE = 'idle';

    public const SYNC_STATUS_RUNNING = 'running';

    public const SYNC_STATUS_FAILED = 'failed';

    /** @var ShipHeroOrderService */
    private $orders;

    /** @var PortalQueueCountsService */
    private $queueCounts;

    public function __construct(ShipHeroOrderService $orders, PortalQueueCountsService $queueCounts)
    {
        $this->orders = $orders;
        $this->queueCounts = $queueCounts;
    }

    public function isQueueTab(string $tab): bool
    {
        $tab = strtolower(trim($tab));

        return in_array($tab, ShipHeroOrderQueueIndex::QUEUE_KINDS, true);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function shouldUseIndex(string $tab, bool $refresh, array $filters): bool
    {
        if ($refresh) {
            return false;
        }

        if (! $this->isQueueTab($tab)) {
            return false;
        }

        $accountId = (int) ($filters['client_account_id'] ?? 0);
        if ($accountId <= 0) {
            return false;
        }

        return $this->indexHasRows($accountId, $tab);
    }

    public function indexHasRows(int $clientAccountId, string $tab): bool
    {
        return ShipHeroOrderQueueIndex::query()
            ->where('client_account_id', $clientAccountId)
            ->where('queue_kind', strtolower(trim($tab)))
            ->exists();
    }

    public function dispatchAccountQueueSync(int $clientAccountId, string $tab): void
    {
        $tab = strtolower(trim($tab));
        if ($clientAccountId <= 0 || ! $this->isQueueTab($tab)) {
            return;
        }

        $lockKey = sprintf('order_queue_sync_dispatch:%d:%s', $clientAccountId, $tab);
        if (Cache::has($lockKey)) {
            return;
        }

        Cache::put($lockKey, 1, now()->addMinutes(2));
        PatchHomeDashboardAccountJob::dispatchAfterHttp($clientAccountId, $tab);
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyListPayload(ClientAccount $account, int $clientAccountId, string $tab, bool $dispatchRequested = false): array
    {
        $syncStatus = (string) ($account->order_queue_sync_status ?? self::SYNC_STATUS_IDLE);
        $pending = $this->isTabSyncPending($account, $clientAccountId, $tab, $dispatchRequested);

        return [
            'rows' => [],
            'pagination' => [
                'has_next_page' => false,
                'end_cursor' => null,
            ],
            'meta' => [
                'client_account_id' => $clientAccountId,
                'from_index' => true,
                'queue_total' => 0,
                'queue_count_metric' => $this->queueCountMetricForTab($tab),
                'refresh_pending' => $pending,
                'order_queue_sync_status' => $syncStatus,
                'order_queue_synced_at' => $account->order_queue_synced_at !== null
                    ? $account->order_queue_synced_at->toIso8601String()
                    : null,
                'index_has_rows' => $this->indexHasRows($clientAccountId, $tab),
                'message' => $pending
                    ? 'Order index is syncing from ShipHero. Refresh again shortly.'
                    : '',
            ],
        ];
    }

    public function isTabSyncPending(ClientAccount $account, int $clientAccountId, string $tab, bool $dispatchRequested = false): bool
    {
        $tab = strtolower(trim($tab));
        $syncStatus = (string) ($account->order_queue_sync_status ?? self::SYNC_STATUS_IDLE);

        if ($syncStatus === self::SYNC_STATUS_FAILED) {
            return false;
        }

        if ($syncStatus === self::SYNC_STATUS_RUNNING) {
            $started = $account->order_queue_sync_started_at;
            if ($started !== null && $started->diffInMinutes(now()) > 75) {
                return false;
            }

            $lockKey = sprintf('order_queue_sync_dispatch:%d:%s', $clientAccountId, $tab);
            if (Cache::has($lockKey)) {
                return true;
            }

            return ! $this->indexHasRows($clientAccountId, $tab);
        }

        $lockKey = sprintf('order_queue_sync_dispatch:%d:%s', $clientAccountId, $tab);
        if (Cache::has($lockKey)) {
            return true;
        }

        if ($this->indexHasRows($clientAccountId, $tab)) {
            return false;
        }

        return $dispatchRequested;
    }

    public function shouldAutoDispatchTabSync(ClientAccount $account, int $clientAccountId, string $tab): bool
    {
        $tab = strtolower(trim($tab));
        if ($clientAccountId <= 0 || ! $this->isQueueTab($tab)) {
            return false;
        }

        if ($this->indexHasRows($clientAccountId, $tab)) {
            return false;
        }

        $syncStatus = (string) ($account->order_queue_sync_status ?? self::SYNC_STATUS_IDLE);
        if ($syncStatus === self::SYNC_STATUS_RUNNING) {
            $started = $account->order_queue_sync_started_at;
            if ($started !== null && $started->diffInMinutes(now()) <= 75) {
                return false;
            }
        }

        $lockKey = sprintf('order_queue_sync_dispatch:%d:%s', $clientAccountId, $tab);
        if (Cache::has($lockKey)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listFromIndex(array $filters): array
    {
        $clientAccountId = (int) ($filters['client_account_id'] ?? 0);
        $tab = strtolower(trim((string) ($filters['tab'] ?? 'manage')));
        if ($clientAccountId <= 0 || ! $this->isQueueTab($tab)) {
            throw new RuntimeException('Order index list requires a queue tab and client_account_id.');
        }

        $first = max(1, min(100, (int) ($filters['first'] ?? 20)));
        $after = isset($filters['after']) ? trim((string) $filters['after']) : '';
        $offset = 0;
        if ($after !== '' && preg_match('/^idx:(\d+)$/', $after, $m)) {
            $offset = max(0, (int) $m[1]);
        }

        $account = ClientAccount::query()->find($clientAccountId);
        if ($account === null) {
            throw new RuntimeException('Client account not found.');
        }

        $context = $this->queueCounts->contextForAccount($account, [
            'order_date_from' => $filters['order_date_from'] ?? null,
            'order_date_to' => $filters['order_date_to'] ?? null,
        ]);

        $query = ShipHeroOrderQueueIndex::query()
            ->where('client_account_id', $clientAccountId)
            ->where('queue_kind', $tab);

        $holdReason = strtolower(trim((string) ($filters['hold_reason'] ?? '')));
        $orderNumber = ltrim(trim((string) ($filters['order_number'] ?? '')), '#');

        if ($tab === ShipHeroOrderQueueIndex::KIND_ON_HOLD) {
            if ($holdReason !== '') {
                $this->applyHoldReasonFilterToQuery($query, $holdReason);
            }
            $this->applyActiveOnHoldScopeToQuery($query);
        }

        if ($orderNumber !== '') {
            $needle = strtolower($orderNumber);
            $query->where(function ($q) use ($needle) {
                $q->where('order_number_search', 'like', '%'.$needle.'%')
                    ->orWhere('order_number_search', $needle);
            });
        } else {
            $this->applyDateWindowToQuery($query, $tab, $context, $filters);
        }

        $queueTotal = $orderNumber === ''
            ? $this->countForAccountTabWithSemantics($clientAccountId, $tab, $context, $filters, $holdReason !== '' ? $holdReason : null)
            : (clone $query)->count();

        $total = (clone $query)->count();
        $indexRows = $query
            ->orderByDesc($tab === ShipHeroOrderQueueIndex::KIND_SHIPPED ? 'ship_date' : 'order_date')
            ->orderByDesc('shiphero_order_id')
            ->offset($offset)
            ->limit($first)
            ->get();

        $rows = [];
        foreach ($indexRows as $indexRow) {
            $payload = is_array($indexRow->list_payload) ? $indexRow->list_payload : [];
            if ($payload === []) {
                continue;
            }
            $rows[] = $payload;
        }

        $next = $offset + $indexRows->count();
        $hasMore = $next < $total;

        $unfilteredExists = $orderNumber === '' && $total === 0
            ? $this->indexHasRows($clientAccountId, $tab)
            : false;

        return [
            'rows' => $rows,
            'pagination' => [
                'has_next_page' => $hasMore,
                'end_cursor' => $hasMore ? 'idx:'.$next : null,
            ],
            'meta' => [
                'client_account_id' => $clientAccountId,
                'from_index' => true,
                'queue_total' => $queueTotal,
                'queue_count_metric' => $this->queueCountMetricForTab($tab),
                'refresh_pending' => $this->isTabSyncPending($account, $clientAccountId, $tab, false),
                'order_queue_sync_status' => (string) ($account->order_queue_sync_status ?? self::SYNC_STATUS_IDLE),
                'order_queue_synced_at' => $account->order_queue_synced_at !== null
                    ? $account->order_queue_synced_at->toIso8601String()
                    : null,
                'index_has_rows' => $this->indexHasRows($clientAccountId, $tab),
                'date_filter_excludes_index' => $unfilteredExists,
            ],
        ];
    }

    /**
     * Incremental queue sync — upserts only; does not purge stale rows (avoids dropping today's
     * shipments when API pagination is truncated). Use syncAccountQueueRange with purge for rebuilds.
     */
    public function syncAccountQueue(int $clientAccountId, string $tab, bool $purgeStale = false): void
    {
        $tab = strtolower(trim($tab));
        if (! $this->isQueueTab($tab)) {
            throw new RuntimeException('Invalid order queue tab: '.$tab);
        }

        $account = ClientAccount::query()->find($clientAccountId);
        if ($account === null) {
            throw new RuntimeException('Client account not found.');
        }

        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            return;
        }

        $context = $tab === ShipHeroOrderQueueIndex::KIND_SHIPPED
            ? $this->shippedIndexSyncContext($account)
            : ($tab === ShipHeroOrderQueueIndex::KIND_ON_HOLD
                ? $this->onHoldIndexSyncContext($account)
                : $this->queueCounts->contextForAccount($account));

        $this->executeQueueSync(
            $clientAccountId,
            $tab,
            $customerId,
            $account,
            $context,
            $purgeStale,
            null,
            true
        );
    }

    /**
     * Sync one queue tab for an explicit order/ship date window (backfill).
     *
     * @return array{pages: int, rows_upserted: int, truncated: bool}
     */
    public function syncAccountQueueRange(
        int $clientAccountId,
        string $tab,
        string $dateFrom,
        string $dateTo,
        bool $purgeStale = false,
        ?int $maxPages = null,
        bool $updateAccountSyncStatus = false
    ): array {
        $tab = strtolower(trim($tab));
        if (! $this->isQueueTab($tab)) {
            throw new RuntimeException('Invalid order queue tab: '.$tab);
        }

        $account = ClientAccount::query()->find($clientAccountId);
        if ($account === null) {
            throw new RuntimeException('Client account not found.');
        }

        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            return ['pages' => 0, 'rows_upserted' => 0, 'truncated' => false];
        }

        $context = $this->buildRangeContext($account, $tab, $dateFrom, $dateTo);

        return $this->executeQueueSync(
            $clientAccountId,
            $tab,
            $customerId,
            $account,
            $context,
            $purgeStale,
            $maxPages,
            $updateAccountSyncStatus
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{pages: int, rows_upserted: int, truncated: bool}
     */
    private function executeQueueSync(
        int $clientAccountId,
        string $tab,
        string $customerId,
        ClientAccount $account,
        array $context,
        bool $purgeStale,
        ?int $maxPages,
        bool $updateAccountSyncStatus
    ): array {
        if ($updateAccountSyncStatus) {
            $this->markSyncRunning($account);
        }

        $syncStarted = now();
        $rowsUpserted = 0;
        $truncated = false;

        try {
            $filters = $this->buildSyncFilters($tab, $customerId, $context);
            $after = null;
            $pages = 0;
            if ($maxPages === null) {
                $maxPages = $tab === ShipHeroOrderQueueIndex::KIND_SHIPPED ? 200 : 50;
            }

            do {
                $filters['after'] = $after;
                $filters['first'] = 100;
                $page = $tab === ShipHeroOrderQueueIndex::KIND_SHIPPED
                    ? $this->orders->listShippedOrders($filters)
                    : $this->orders->listOrders($filters);
                $rows = is_array($page['rows'] ?? null) ? $page['rows'] : [];
                $this->upsertRows($clientAccountId, $tab, $rows, $syncStarted);
                $rowsUpserted += count($rows);
                $after = $page['pagination']['end_cursor'] ?? null;
                $hasNext = (bool) ($page['pagination']['has_next_page'] ?? false);
                $pages++;
                if ($hasNext && $after !== null && $pages >= $maxPages) {
                    $truncated = true;
                    break;
                }
            } while ($hasNext && $after !== null && $pages < $maxPages);

            // Drop rows that left this queue since the last full (non-truncated) sync pass.
            if (! $truncated) {
                ShipHeroOrderQueueIndex::query()
                    ->where('client_account_id', $clientAccountId)
                    ->where('queue_kind', $tab)
                    ->where(function ($q) use ($syncStarted) {
                        $q->whereNull('last_seen_at')
                            ->orWhere('last_seen_at', '<', $syncStarted);
                    })
                    ->delete();
            }

            if ($updateAccountSyncStatus) {
                $this->markSyncCompleted($account);
            }

            Log::info('order_queue_index.synced', [
                'client_account_id' => $clientAccountId,
                'tab' => $tab,
                'pages' => $pages,
                'rows_upserted' => $rowsUpserted,
                'truncated' => $truncated,
                'purged_stale' => ! $truncated,
            ]);

            return [
                'pages' => $pages,
                'rows_upserted' => $rowsUpserted,
                'truncated' => $truncated,
            ];
        } catch (Throwable $e) {
            if ($updateAccountSyncStatus) {
                $this->markSyncFailed($account, $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRangeContext(ClientAccount $account, string $tab, string $dateFrom, string $dateTo): array
    {
        $context = $this->queueCounts->contextForAccount($account, [
            'order_date_from' => $dateFrom,
            'order_date_to' => $dateTo,
        ]);

        $timezone = (string) ($context['timezone'] ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE);
        $fromBoundary = Carbon::parse($dateFrom, $timezone)->startOfDay()->toIso8601String();
        $toBoundary = Carbon::parse($dateTo, $timezone)->endOfDay()->toIso8601String();

        if ($tab === ShipHeroOrderQueueIndex::KIND_AWAITING) {
            $context['awaiting_from'] = $fromBoundary;
            $context['awaiting_to'] = $toBoundary;
        } elseif ($tab !== ShipHeroOrderQueueIndex::KIND_SHIPPED) {
            $context['open_from'] = $fromBoundary;
            $context['open_to'] = $toBoundary;
        }

        return $context;
    }

    public function syncAllLinkedAccounts(?string $tab = null): void
    {
        $accounts = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('id')
            ->get();

        $tabs = $tab !== null && $tab !== '' && $tab !== 'all'
            ? [strtolower(trim($tab))]
            : ShipHeroOrderQueueIndex::QUEUE_KINDS;

        foreach ($accounts as $account) {
            foreach ($tabs as $queueTab) {
                if (! $this->isQueueTab($queueTab)) {
                    continue;
                }
                try {
                    $this->syncAccountQueue((int) $account->id, $queueTab);
                } catch (Throwable $e) {
                    Log::warning('order_queue_index.account_sync_failed', [
                        'client_account_id' => (int) $account->id,
                        'tab' => $queueTab,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function upsertRows(int $clientAccountId, string $tab, array $rows, ?Carbon $seenAt = null): void
    {
        $tab = strtolower(trim($tab));
        if (! $this->isQueueTab($tab)) {
            return;
        }

        $seenAt = $seenAt ?? now();
        $now = now();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $orderId = trim((string) ($row['id'] ?? ''));
            if ($orderId === '') {
                continue;
            }

            if ($tab === ShipHeroOrderQueueIndex::KIND_ON_HOLD && ! empty($row['has_backorder'])) {
                continue;
            }

            $orderNumber = trim((string) ($row['order_number'] ?? ''));
            $holdReason = $this->machineHoldReasonFromRow($row);

            ShipHeroOrderQueueIndex::query()->updateOrInsert(
                [
                    'client_account_id' => $clientAccountId,
                    'shiphero_order_id' => $orderId,
                    'queue_kind' => $tab,
                ],
                [
                    'hold_reason' => $holdReason,
                    'ready_to_ship' => (bool) ($row['ready_to_ship'] ?? ($tab === ShipHeroOrderQueueIndex::KIND_AWAITING)),
                    'has_backorder' => (bool) ($row['has_backorder'] ?? ($tab === ShipHeroOrderQueueIndex::KIND_BACKORDER)),
                    'order_number' => $orderNumber !== '' ? $orderNumber : null,
                    'order_number_search' => $orderNumber !== '' ? strtolower(ltrim($orderNumber, '#')) : null,
                    'recipient_name' => (string) ($row['recipient_name'] ?? ''),
                    'order_date' => $this->parseTimestamp($row['order_date'] ?? null),
                    'ship_date' => $this->parseTimestamp($row['ship_date'] ?? null),
                    'country' => (string) ($row['country'] ?? ''),
                    'display_status' => (string) ($row['display_status'] ?? ''),
                    'list_payload' => json_encode($row),
                    'indexed_at' => $now,
                    'last_seen_at' => $seenAt,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            ShipHeroOrderQueueIndex::query()
                ->where('client_account_id', $clientAccountId)
                ->where('shiphero_order_id', $orderId)
                ->where('queue_kind', '!=', $tab)
                ->delete();
        }
    }

    public function invalidateOrder(int $clientAccountId, string $shipheroOrderId): void
    {
        $orderId = trim($shipheroOrderId);
        if ($orderId === '') {
            return;
        }

        ShipHeroOrderQueueIndex::query()
            ->where('client_account_id', $clientAccountId)
            ->where('shiphero_order_id', $orderId)
            ->delete();
    }

    /**
     * @return list<string> affected queue tabs
     */
    public function reconcileOrder(int $clientAccountId, string $shipheroOrderId): array
    {
        $orderId = trim($shipheroOrderId);
        if ($clientAccountId <= 0 || $orderId === '') {
            return [];
        }

        $account = ClientAccount::query()->find($clientAccountId);
        if ($account === null) {
            return [];
        }

        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            return [];
        }

        $previousTabs = ShipHeroOrderQueueIndex::query()
            ->where('client_account_id', $clientAccountId)
            ->where('shiphero_order_id', $orderId)
            ->pluck('queue_kind')
            ->map(static fn ($tab) => strtolower(trim((string) $tab)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $listRow = $this->orders->fetchOrderListRowForIndex($orderId, $customerId);
        $resolvedOrderId = $listRow !== null ? trim((string) ($listRow['id'] ?? $orderId)) : $orderId;

        $this->invalidateOrder($clientAccountId, $resolvedOrderId);
        if ($resolvedOrderId !== $orderId) {
            $this->invalidateOrder($clientAccountId, $orderId);
        }

        $affected = $previousTabs;
        if ($listRow === null) {
            return array_values(array_unique($affected));
        }

        $tab = $this->orders->classifyOrderQueueTab($listRow);
        if ($tab !== null && $this->isQueueTab($tab)) {
            $listRow['ready_to_ship'] = $tab === ShipHeroOrderQueueIndex::KIND_AWAITING;
            $this->upsertRows($clientAccountId, $tab, [$listRow]);
            $affected[] = $tab;
        }

        return array_values(array_unique($affected));
    }

    /**
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    public function aggregateDashboardSection(string $sectionKey, bool $hybridShippedFallback = false): array
    {
        $mapping = $this->sectionIndexMapping($sectionKey);
        if ($mapping === null) {
            throw new RuntimeException('Unsupported dashboard section for index aggregate: '.$sectionKey);
        }

        $accounts = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'status', 'shiphero_customer_account_id']);

        $rows = [];
        $total = 0;
        $truncated = false;
        foreach ($accounts as $account) {
            $context = $this->queueCounts->contextForDashboardSection($account, $sectionKey);
            $count = $this->countForDashboardSection((int) $account->id, $sectionKey, $context);

            if ($hybridShippedFallback && $sectionKey === OrderDashboardSection::KEY_SHIPPED) {
                $customerId = trim((string) ($context['customer_id'] ?? $account->shiphero_customer_account_id ?? ''));
                if ($customerId !== '') {
                    try {
                        $live = $this->orders->countShipments([
                            'customer_account_id' => $customerId,
                            'date_from' => $context['shipped_from'],
                            'date_to' => $context['shipped_to'],
                            'timezone' => $context['timezone'] ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
                            'max_pages' => 50,
                        ]);
                        $liveCount = (int) ($live['count'] ?? 0);
                        if ($liveCount > $count) {
                            $count = $liveCount;
                        }
                        $truncated = $truncated || (bool) ($live['truncated'] ?? false);
                    } catch (Throwable $e) {
                        Log::warning('order_queue_index.hybrid_shipped_count_failed', [
                            'client_account_id' => (int) $account->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            }

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
     * Unique on-hold orders across all accounts (excludes backorder). Matches ShipHero "orders on hold"
     * rather than summing per-hold-type sections (which double-counts multi-hold orders).
     */
    public function aggregateDistinctOnHoldTotal(): int
    {
        $accounts = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->get(['id']);

        $total = 0;
        foreach ($accounts as $account) {
            $context = $this->queueCounts->contextForOnHoldDashboardTotal($account);
            $total += $this->countDistinctOnHoldForAccount((int) $account->id, $context);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function countDistinctOnHoldForAccount(int $clientAccountId, array $context): int
    {
        if ($clientAccountId <= 0) {
            return 0;
        }

        $query = ShipHeroOrderQueueIndex::query()
            ->where('client_account_id', $clientAccountId)
            ->where('queue_kind', ShipHeroOrderQueueIndex::KIND_ON_HOLD);
        $this->applyActiveOnHoldScopeToQuery($query);

        $this->applyDateWindowToQuery($query, ShipHeroOrderQueueIndex::KIND_ON_HOLD, $context, []);

        return (int) $query->distinct()->count('shiphero_order_id');
    }

    public function indexHasRowsForQueueTab(string $tab): bool
    {
        $tab = strtolower(trim($tab));
        if (! $this->isQueueTab($tab)) {
            return false;
        }

        $query = ShipHeroOrderQueueIndex::query()->where('queue_kind', $tab);
        if ($tab === ShipHeroOrderQueueIndex::KIND_ON_HOLD) {
            $this->applyActiveOnHoldScopeToQuery($query);
        }

        return $query->exists();
    }

    /**
     * On-hold rows that are fulfilled/shipped are stale index state — exclude from counts.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    private function applyActiveOnHoldScopeToQuery($query): void
    {
        $query->where('has_backorder', false);
        $query->where(function ($q) {
            $q->where(function ($inner) {
                $inner->whereNull('display_status')
                    ->orWhere('display_status', '=', '')
                    ->orWhereRaw("LOWER(display_status) NOT LIKE '%fulfilled%'")
                    ->orWhereRaw("LOWER(display_status) NOT LIKE '%shipped%'");
            })->where(function ($inner) {
                $inner->whereRaw(
                    "LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(list_payload, '$.raw_fulfillment_status')), '')) NOT IN ('fulfilled', 'shipped')"
                );
            });
        });
    }

    public function indexHasRowsForSection(string $sectionKey): bool
    {
        $mapping = $this->sectionIndexMapping($sectionKey);
        if ($mapping === null) {
            return false;
        }

        $query = ShipHeroOrderQueueIndex::query()
            ->where('queue_kind', $mapping['queue_kind']);

        if ($mapping['hold_reason'] !== null) {
            $this->applyHoldReasonFilterToQuery($query, $mapping['hold_reason']);
        }

        if ($mapping['queue_kind'] === ShipHeroOrderQueueIndex::KIND_ON_HOLD) {
            $this->applyActiveOnHoldScopeToQuery($query);
        }

        return $query->exists();
    }

    public function indexHasAnyRows(): bool
    {
        return ShipHeroOrderQueueIndex::query()->exists();
    }

    /**
     * Index is trustworthy enough to overlay dashboard section breakdowns (not primary totals).
     */
    public function indexIsHealthyForSection(string $sectionKey): bool
    {
        $mapping = $this->sectionIndexMapping($sectionKey);
        if ($mapping === null) {
            return false;
        }

        $tab = $mapping['queue_kind'];
        $linkedCount = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->count();

        if ($linkedCount <= 0) {
            return false;
        }

        $accountsWithRows = (int) ShipHeroOrderQueueIndex::query()
            ->where('queue_kind', $tab)
            ->distinct()
            ->count('client_account_id');

        if ($accountsWithRows < max(1, (int) ceil($linkedCount * 0.5))) {
            return false;
        }

        $timezone = PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE;
        $todayStart = Carbon::now($timezone)->startOfDay();
        $todayEnd = Carbon::now($timezone)->endOfDay();

        if ($tab === ShipHeroOrderQueueIndex::KIND_SHIPPED) {
            return ShipHeroOrderQueueIndex::query()
                ->where('queue_kind', $tab)
                ->where('ship_date', '>=', $todayStart)
                ->where('ship_date', '<=', $todayEnd)
                ->exists();
        }

        if ($tab === ShipHeroOrderQueueIndex::KIND_AWAITING) {
            $rtsFrom = Carbon::parse(PortalQueueCountsService::RTS_DASHBOARD_ORDER_FROM, $timezone)->startOfDay();

            return ShipHeroOrderQueueIndex::query()
                ->where('queue_kind', $tab)
                ->where('order_date', '>=', $rtsFrom)
                ->exists();
        }

        if ($tab === ShipHeroOrderQueueIndex::KIND_ON_HOLD) {
            return ShipHeroOrderQueueIndex::query()
                ->where('queue_kind', $tab)
                ->where('order_date', '>=', $todayStart)
                ->where('order_date', '<=', $todayEnd)
                ->exists();
        }

        return $accountsWithRows > 0;
    }

    /**
     * Index-backed totals for diagnose parity checks.
     */
    public function aggregateReadyToShipFromIndex(): int
    {
        $result = $this->aggregateDashboardSection(OrderDashboardSection::KEY_READY_TO_SHIP, false);

        return (int) ($result['total_count'] ?? 0);
    }

    public function aggregateShippedTodayFromIndex(): int
    {
        $result = $this->aggregateDashboardSection(OrderDashboardSection::KEY_SHIPPED, false);

        return (int) ($result['total_count'] ?? 0);
    }

    public function aggregateOnHoldTodayFromIndex(): int
    {
        return $this->aggregateDistinctOnHoldTotal();
    }

    /**
     * @param  array<string, mixed>  $context  PortalQueueCountsService::contextForDashboardSection payload
     */
    public function countForDashboardSection(int $clientAccountId, string $sectionKey, array $context): int
    {
        $mapping = $this->sectionIndexMapping($sectionKey);
        if ($mapping === null || $clientAccountId <= 0) {
            return 0;
        }

        return $this->countForAccountTabWithSemantics(
            $clientAccountId,
            $mapping['queue_kind'],
            $context,
            [],
            $mapping['hold_reason']
        );
    }

    public function queueCountMetricForTab(string $tab): string
    {
        return strtolower(trim($tab)) === ShipHeroOrderQueueIndex::KIND_SHIPPED ? 'shipments' : 'orders';
    }

    /**
     * Shared queue total semantics for dashboard pills and orders list header.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $filters
     */
    public function countForAccountTabWithSemantics(
        int $clientAccountId,
        string $tab,
        array $context,
        array $filters = [],
        ?string $holdReason = null
    ): int {
        $tab = strtolower(trim($tab));
        if ($clientAccountId <= 0 || ! $this->isQueueTab($tab)) {
            return 0;
        }

        if ($tab === ShipHeroOrderQueueIndex::KIND_SHIPPED) {
            $query = ShipHeroOrderQueueIndex::query()
                ->where('client_account_id', $clientAccountId)
                ->where('queue_kind', $tab);
            $this->applyDateWindowToQuery($query, $tab, $context, $filters);

            return $this->sumShippedLabelCountFromRows($query->get(['list_payload']));
        }

        $query = ShipHeroOrderQueueIndex::query()
            ->where('client_account_id', $clientAccountId)
            ->where('queue_kind', $tab);

        if ($tab === ShipHeroOrderQueueIndex::KIND_ON_HOLD) {
            if ($holdReason !== null && trim($holdReason) !== '') {
                $this->applyHoldReasonFilterToQuery($query, $holdReason);
            }
            $this->applyActiveOnHoldScopeToQuery($query);

            $this->applyDateWindowToQuery($query, $tab, $context, $filters);

            return (int) $query->distinct()->count('shiphero_order_id');
        }

        $this->applyDateWindowToQuery($query, $tab, $context, $filters);

        return (int) $query->count();
    }

    /**
     * Sum shipment labels for today's ship_date window (matches ShipHero shipments report).
     *
     * @param  array<string, mixed>  $context
     */
    public function countShippedTodayFromIndex(int $clientAccountId, array $context): int
    {
        return $this->countForAccountTabWithSemantics(
            $clientAccountId,
            ShipHeroOrderQueueIndex::KIND_SHIPPED,
            $context
        );
    }

    /**
     * @param  iterable<ShipHeroOrderQueueIndex|object{list_payload?: mixed}>  $rows
     */
    private function sumShippedLabelCountFromRows(iterable $rows): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $payload = $row->list_payload ?? null;
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            if (! is_array($payload)) {
                $payload = [];
            }
            $total += max(1, (int) ($payload['shipped_label_count'] ?? 1));
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $context  PortalQueueCountsService::contextForAccount payload
     */
    public function countForAccountTab(int $clientAccountId, string $tab, array $context): int
    {
        return $this->countForAccountTabWithSemantics($clientAccountId, $tab, $context);
    }

    /**
     * @return array{queue_kind: string, hold_reason: string|null}|null
     */
    private function sectionIndexMapping(string $sectionKey): ?array
    {
        switch ($sectionKey) {
            case OrderDashboardSection::KEY_READY_TO_SHIP:
                return ['queue_kind' => ShipHeroOrderQueueIndex::KIND_AWAITING, 'hold_reason' => null];
            case OrderDashboardSection::KEY_SHIPPED:
                return ['queue_kind' => ShipHeroOrderQueueIndex::KIND_SHIPPED, 'hold_reason' => null];
            case OrderDashboardSection::KEY_HOLD_BACKORDER:
                return ['queue_kind' => ShipHeroOrderQueueIndex::KIND_BACKORDER, 'hold_reason' => null];
            case OrderDashboardSection::KEY_HOLD_OPERATOR:
                return ['queue_kind' => ShipHeroOrderQueueIndex::KIND_ON_HOLD, 'hold_reason' => 'operator'];
            case OrderDashboardSection::KEY_HOLD_ADDRESS:
                return ['queue_kind' => ShipHeroOrderQueueIndex::KIND_ON_HOLD, 'hold_reason' => 'address'];
            case OrderDashboardSection::KEY_HOLD_FRAUD:
                return ['queue_kind' => ShipHeroOrderQueueIndex::KIND_ON_HOLD, 'hold_reason' => 'fraud'];
            case OrderDashboardSection::KEY_HOLD_PAYMENT:
                return ['queue_kind' => ShipHeroOrderQueueIndex::KIND_ON_HOLD, 'hold_reason' => 'payment'];
            case OrderDashboardSection::KEY_HOLD_USER:
                return ['queue_kind' => ShipHeroOrderQueueIndex::KIND_ON_HOLD, 'hold_reason' => 'user'];
            default:
                return null;
        }
    }

    /**
     * Match dashboard / ShipHero hold filters — orders can have multiple active holds.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    private function applyHoldReasonFilterToQuery($query, string $holdReason): void
    {
        $holdReason = strtolower(trim($holdReason));
        if ($holdReason === '') {
            return;
        }

        $holdField = $this->holdReasonToPayloadField($holdReason);

        $query->where(function ($q) use ($holdReason, $holdField) {
            $q->where('hold_reason', $holdReason);

            if ($holdField !== null) {
                $q->orWhereRaw(
                    "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(list_payload, '$.holds.{$holdField}')), 'false') IN ('true', '1')"
                );
            }

            if ($holdReason === 'user') {
                $q->orWhereRaw(
                    "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(list_payload, '$.holds.client_hold')), 'false') IN ('true', '1')"
                )->orWhereRaw(
                    "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(list_payload, '$.is_crm_user_hold')), 'false') IN ('true', '1')"
                );
            }
        });
    }

    private function holdReasonToPayloadField(string $holdReason): ?string
    {
        switch ($holdReason) {
            case 'fraud':
                return 'fraud_hold';
            case 'payment':
                return 'payment_hold';
            case 'address':
                return 'address_hold';
            case 'operator':
                return 'operator_hold';
            default:
                return null;
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $filters
     */
    private function applyDateWindowToQuery($query, string $tab, array $context, array $filters): void
    {
        if ($tab === ShipHeroOrderQueueIndex::KIND_AWAITING) {
            $from = $this->parseTimestamp($context['awaiting_from'] ?? null);
            $to = $this->parseTimestamp($context['awaiting_to'] ?? null);
            if ($from !== null) {
                $query->where('order_date', '>=', $from);
            }
            if ($to !== null) {
                $query->where('order_date', '<=', $to);
            }

            return;
        }

        if ($tab === ShipHeroOrderQueueIndex::KIND_SHIPPED) {
            $from = $this->parseTimestamp($context['shipped_from'] ?? null);
            $to = $this->parseTimestamp($context['shipped_to'] ?? null);
            if ($from !== null) {
                $query->where('ship_date', '>=', $from);
            }
            if ($to !== null) {
                $query->where('ship_date', '<=', $to);
            }

            return;
        }

        if ($tab === ShipHeroOrderQueueIndex::KIND_ON_HOLD) {
            $from = $this->parseTimestamp($context['open_from'] ?? null);
            $to = $this->parseTimestamp($context['open_to'] ?? null);
            if (! empty($filters['order_date_from'])) {
                $from = $this->parseTimestamp($filters['order_date_from'].' 00:00:00');
            }
            if (! empty($filters['order_date_to'])) {
                $to = $this->parseTimestamp($filters['order_date_to'].' 23:59:59');
            }
            if ($from !== null) {
                $query->where('order_date', '>=', $from);
            }
            if ($to !== null) {
                $query->where('order_date', '<=', $to);
            }

            return;
        }

        $from = $this->parseTimestamp($context['open_from'] ?? null);
        $to = $this->parseTimestamp($context['open_to'] ?? null);
        if (! empty($filters['order_date_from'])) {
            $from = $this->parseTimestamp($filters['order_date_from'].' 00:00:00');
        }
        if (! empty($filters['order_date_to'])) {
            $to = $this->parseTimestamp($filters['order_date_to'].' 23:59:59');
        }
        if ($from !== null) {
            $query->where('order_date', '>=', $from);
        }
        if ($to !== null) {
            $query->where('order_date', '<=', $to);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildSyncFilters(string $tab, string $customerId, array $context): array
    {
        $filters = [
            'customer_account_id' => $customerId,
            'tab' => $tab,
            'timezone' => $context['timezone'] ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
        ];

        if ($tab === ShipHeroOrderQueueIndex::KIND_AWAITING) {
            $filters['order_date_from'] = $this->isoDateOnly($context['awaiting_from'] ?? null);
            $filters['order_date_to'] = $this->isoDateOnly($context['awaiting_to'] ?? null);
        } elseif ($tab === ShipHeroOrderQueueIndex::KIND_SHIPPED) {
            $filters['order_date_from'] = $this->isoDateOnly($context['shipped_from'] ?? null);
            $filters['order_date_to'] = $this->isoDateOnly($context['shipped_to'] ?? null);
        } elseif ($tab === ShipHeroOrderQueueIndex::KIND_ON_HOLD) {
            $filters['order_date_from'] = $this->isoDateOnly($context['open_from'] ?? null);
            $filters['order_date_to'] = $this->isoDateOnly($context['open_to'] ?? null);
        } else {
            $filters['order_date_from'] = $this->isoDateOnly($context['open_from'] ?? null);
            $filters['order_date_to'] = $this->isoDateOnly($context['open_to'] ?? null);
        }

        return $filters;
    }

    private function shippedIndexSyncContext(ClientAccount $account): array
    {
        $timezone = $this->queueCounts->contextForAccount($account)['timezone']
            ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE;
        $now = Carbon::now($timezone);

        return $this->queueCounts->contextForAccount($account, [
            'order_date_from' => $now->toDateString(),
            'order_date_to' => $now->toDateString(),
        ]);
    }

    private function onHoldIndexSyncContext(ClientAccount $account): array
    {
        return $this->queueCounts->contextForOnHoldDashboardTotal($account);
    }

    private function markSyncRunning(ClientAccount $account): void
    {
        $account->order_queue_sync_status = self::SYNC_STATUS_RUNNING;
        $account->order_queue_sync_started_at = now();
        $account->save();
    }

    private function markSyncCompleted(ClientAccount $account): void
    {
        $account->order_queue_sync_status = self::SYNC_STATUS_IDLE;
        $account->order_queue_synced_at = now();
        $account->order_queue_sync_started_at = null;
        $account->save();
    }

    private function markSyncFailed(ClientAccount $account, string $message): void
    {
        $account->order_queue_sync_status = self::SYNC_STATUS_FAILED;
        $account->order_queue_sync_started_at = null;
        $account->save();
        Log::warning('order_queue_index.sync_failed', [
            'client_account_id' => (int) $account->id,
            'message' => $message,
        ]);
    }

    private function parseTimestamp($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable $e) {
            return null;
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

    /**
     * @param  array<string, mixed>  $row
     */
    private function machineHoldReasonFromRow(array $row): ?string
    {
        $holds = is_array($row['holds'] ?? null) ? $row['holds'] : [];
        if (! empty($row['is_crm_user_hold']) || ! empty($holds['client_hold'])) {
            return 'user';
        }
        if (! empty($holds['fraud_hold'])) {
            return 'fraud';
        }
        if (! empty($holds['payment_hold'])) {
            return 'payment';
        }
        if (! empty($holds['address_hold'])) {
            return 'address';
        }
        if (! empty($holds['operator_hold'])) {
            return 'operator';
        }

        $label = strtolower(trim((string) ($row['hold_reason'] ?? '')));
        if ($label === '') {
            return null;
        }
        if (str_contains($label, 'fraud')) {
            return 'fraud';
        }
        if (str_contains($label, 'payment')) {
            return 'payment';
        }
        if (str_contains($label, 'address')) {
            return 'address';
        }
        if (str_contains($label, 'user')) {
            return 'user';
        }
        if (str_contains($label, 'operator')) {
            return 'operator';
        }

        return null;
    }
}
