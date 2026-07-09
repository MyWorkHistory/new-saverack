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
    public function emptyListPayload(ClientAccount $account, int $clientAccountId, bool $refreshPending = false): array
    {
        $syncStatus = (string) ($account->order_queue_sync_status ?? self::SYNC_STATUS_IDLE);
        $pending = $refreshPending
            || $syncStatus === self::SYNC_STATUS_RUNNING
            || ($account->order_queue_synced_at === null && $syncStatus !== self::SYNC_STATUS_FAILED);

        return [
            'rows' => [],
            'pagination' => [
                'has_next_page' => false,
                'end_cursor' => null,
            ],
            'meta' => [
                'client_account_id' => $clientAccountId,
                'from_index' => true,
                'refresh_pending' => $pending,
                'order_queue_sync_status' => $syncStatus,
                'order_queue_synced_at' => $account->order_queue_synced_at !== null
                    ? $account->order_queue_synced_at->toIso8601String()
                    : null,
                'message' => $pending
                    ? 'Order index is syncing from ShipHero. Refresh again shortly.'
                    : '',
            ],
        ];
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
        if ($tab === ShipHeroOrderQueueIndex::KIND_ON_HOLD && $holdReason !== '') {
            $this->applyHoldReasonFilterToQuery($query, $holdReason);
        }

        $orderNumber = ltrim(trim((string) ($filters['order_number'] ?? '')), '#');
        if ($orderNumber !== '') {
            $needle = strtolower($orderNumber);
            $query->where(function ($q) use ($needle) {
                $q->where('order_number_search', 'like', '%'.$needle.'%')
                    ->orWhere('order_number_search', $needle);
            });
        } else {
            $this->applyDateWindowToQuery($query, $tab, $context, $filters);
        }

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

        return [
            'rows' => $rows,
            'pagination' => [
                'has_next_page' => $hasMore,
                'end_cursor' => $hasMore ? 'idx:'.$next : null,
            ],
            'meta' => [
                'client_account_id' => $clientAccountId,
                'from_index' => true,
                'refresh_pending' => (string) ($account->order_queue_sync_status ?? self::SYNC_STATUS_IDLE) === self::SYNC_STATUS_RUNNING,
                'order_queue_sync_status' => (string) ($account->order_queue_sync_status ?? self::SYNC_STATUS_IDLE),
                'order_queue_synced_at' => $account->order_queue_synced_at !== null
                    ? $account->order_queue_synced_at->toIso8601String()
                    : null,
            ],
        ];
    }

    public function syncAccountQueue(int $clientAccountId, string $tab, bool $full = true): void
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

        $this->markSyncRunning($account);
        $syncStarted = now();

        try {
            $context = $tab === ShipHeroOrderQueueIndex::KIND_SHIPPED
                ? $this->queueCounts->contextForDashboardSection($account, OrderDashboardSection::KEY_SHIPPED)
                : $this->queueCounts->contextForAccount($account);
            $filters = $this->buildSyncFilters($tab, $customerId, $context);
            $after = null;
            $pages = 0;
            $maxPages = $tab === ShipHeroOrderQueueIndex::KIND_SHIPPED ? 200 : 50;

            do {
                $filters['after'] = $after;
                $filters['first'] = 100;
                $page = $tab === ShipHeroOrderQueueIndex::KIND_SHIPPED
                    ? $this->orders->listShippedOrders($filters)
                    : $this->orders->listOrders($filters);
                $rows = is_array($page['rows'] ?? null) ? $page['rows'] : [];
                $this->upsertRows($clientAccountId, $tab, $rows, $syncStarted);
                $after = $page['pagination']['end_cursor'] ?? null;
                $hasNext = (bool) ($page['pagination']['has_next_page'] ?? false);
                $pages++;
            } while ($hasNext && $after !== null && $pages < $maxPages);

            if ($full) {
                ShipHeroOrderQueueIndex::query()
                    ->where('client_account_id', $clientAccountId)
                    ->where('queue_kind', $tab)
                    ->where(function ($q) use ($syncStarted) {
                        $q->whereNull('last_seen_at')
                            ->orWhere('last_seen_at', '<', $syncStarted);
                    })
                    ->delete();
            }

            $this->markSyncCompleted($account);
            Log::info('order_queue_index.synced', [
                'client_account_id' => $clientAccountId,
                'tab' => $tab,
                'pages' => $pages,
            ]);
        } catch (Throwable $e) {
            $this->markSyncFailed($account, $e->getMessage());
            throw $e;
        }
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
                    $this->syncAccountQueue((int) $account->id, $queueTab, true);
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
    public function aggregateDashboardSection(string $sectionKey): array
    {
        $mapping = $this->sectionIndexMapping($sectionKey);
        if ($mapping === null) {
            throw new RuntimeException('Unsupported dashboard section for index aggregate: '.$sectionKey);
        }

        $accounts = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'status']);

        $rows = [];
        $total = 0;
        foreach ($accounts as $account) {
            $context = $this->queueCounts->contextForDashboardSection($account, $sectionKey);
            $count = $this->countForDashboardSection((int) $account->id, $sectionKey, $context);
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
            'payload' => ['accounts' => $rows, 'truncated' => false],
            'total_count' => $total,
        ];
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

        return $query->exists();
    }

    public function indexHasAnyRows(): bool
    {
        return ShipHeroOrderQueueIndex::query()->exists();
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

        $tab = $mapping['queue_kind'];
        $query = ShipHeroOrderQueueIndex::query()
            ->where('client_account_id', $clientAccountId)
            ->where('queue_kind', $tab);

        if ($mapping['hold_reason'] !== null) {
            $this->applyHoldReasonFilterToQuery($query, $mapping['hold_reason']);
        }

        $this->applyDateWindowToQuery($query, $tab, $context, []);

        return (int) $query->count();
    }

    /**
     * @param  array<string, mixed>  $context  PortalQueueCountsService::contextForAccount payload
     */
    public function countForAccountTab(int $clientAccountId, string $tab, array $context): int
    {
        $tab = strtolower(trim($tab));
        if ($clientAccountId <= 0 || ! $this->isQueueTab($tab)) {
            return 0;
        }

        $query = ShipHeroOrderQueueIndex::query()
            ->where('client_account_id', $clientAccountId)
            ->where('queue_kind', $tab);

        $this->applyDateWindowToQuery($query, $tab, $context, []);

        return (int) $query->count();
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
        } else {
            $filters['order_date_from'] = $this->isoDateOnly($context['open_from'] ?? null);
            $filters['order_date_to'] = $this->isoDateOnly($context['open_to'] ?? null);
        }

        return $filters;
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
