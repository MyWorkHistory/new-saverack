<?php

namespace App\Services;

use App\Jobs\PatchHomeDashboardAccountJob;
use App\Models\ClientAccount;
use App\Models\OrderDashboardSection;
use App\Models\ShipHeroOrderQueueIndex;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Portal dashboard order queue totals.
 *
 * Web requests count ONE queue at a time (short). The SPA loads four queues sequentially.
 */
class PortalQueueCountsService
{
    public const QUEUES = ['awaiting', 'on_hold', 'backorder', 'shipped'];

    /** ShipHero operational day for US accounts (matches ShipHero UI / shipments report). */
    public const DEFAULT_ACCOUNT_TIMEZONE = 'America/New_York';

    private const CACHE_TTL_MINUTES = 10;

    /** One queue per HTTP request — keep this fast to avoid Cloudflare 502. */
    private const PER_TAB_SECONDS = 12;

    /** Shipped uses ShipHero shipments API; allow enough time to paginate busy days. */
    private const SHIPPED_TAB_SECONDS = 45;

    private const MAX_PAGES_PER_TAB = 2;

    /** 100 shipments/page — 50 pages supports 5k shipments/day per account. */
    private const SHIPPED_MAX_PAGES = 50;

    /** Background refresh (no HTTP deadline) can scan more pages. */
    private const SHIPPED_MAX_PAGES_BACKGROUND = 200;

    /** On-hold and backorder dashboard totals include orders placed in this window. */
    public const OPEN_QUEUE_LOOKBACK_DAYS = 29;

    /** Ready-to-ship sync / dashboard alignment window (order_date from). */
    public const RTS_DASHBOARD_ORDER_FROM = '2026-05-01';

    /** @var ShipHeroOrderService */
    private $orders;

    public function __construct(ShipHeroOrderService $orders)
    {
        $this->orders = $orders;
    }

    /**
     * @param  array{order_date_from?: string|null, order_date_to?: string|null}  $validated
     * @return array<string, mixed>
     */
    public function contextForAccount(ClientAccount $account, array $validated = []): array
    {
        $clientAccountId = (int) $account->id;
        $customerId = trim((string) $account->shiphero_customer_account_id);
        $timezone = $this->accountTimezone($account);

        $now = Carbon::now($timezone);
        $awaitingFrom = $this->dateStartIso(self::RTS_DASHBOARD_ORDER_FROM, $timezone);
        $awaitingTo = $this->dateEndIso($now->copy()->toDateString(), $timezone);
        $openFrom = $this->dateStartIso(
            $now->copy()->subDays(self::OPEN_QUEUE_LOOKBACK_DAYS)->toDateString(),
            $timezone
        );
        $openTo = $this->dateEndIso($now->copy()->toDateString(), $timezone);

        $shippedFromInput = $validated['order_date_from'] ?? null;
        $shippedToInput = $validated['order_date_to'] ?? null;
        if ($shippedFromInput !== null && $shippedToInput !== null) {
            $shippedFrom = $this->dateStartIso((string) $shippedFromInput, $timezone);
            $shippedTo = $this->dateEndIso((string) $shippedToInput, $timezone);
        } else {
            $today = $now->toDateString();
            $shippedFrom = $this->dateStartIso($today, $timezone);
            $shippedTo = $this->dateEndIso($today, $timezone);
        }

        return [
            'client_account_id' => $clientAccountId,
            'customer_id' => $customerId,
            'timezone' => $timezone,
            'last_good_key' => 'orders:queue_counts:last:'.$clientAccountId,
            'awaiting_from' => $awaitingFrom,
            'awaiting_to' => $awaitingTo,
            'open_from' => $openFrom,
            'open_to' => $openTo,
            'shipped_from' => $shippedFrom,
            'shipped_to' => $shippedTo,
        ];
    }

    /**
     * Queue count context for admin Home dashboard sections (matches Orders list date presets).
     *
     * @return array<string, mixed>
     */
    public function contextForDashboardSection(ClientAccount $account, string $sectionKey): array
    {
        $context = $this->contextForAccount($account);

        $timezone = (string) ($context['timezone'] ?? self::DEFAULT_ACCOUNT_TIMEZONE);

        if ($sectionKey === OrderDashboardSection::KEY_SHIPPED) {
            $today = Carbon::now($timezone)->toDateString();
            $context['shipped_from'] = $this->dateStartIso($today, $timezone);
            $context['shipped_to'] = $this->dateEndIso($today, $timezone);
        }

        if ($sectionKey === OrderDashboardSection::KEY_READY_TO_SHIP) {
            $context['awaiting_from'] = $this->dateStartIso(self::RTS_DASHBOARD_ORDER_FROM, $timezone);
            $context['awaiting_to'] = $this->dateEndIso(Carbon::now($timezone)->toDateString(), $timezone);
        }

        return $context;
    }

    /**
     * Instant dashboard snapshot from per-queue cache / last good — never calls ShipHero.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function respondFromCache(array $context): array
    {
        $parts = [];
        $truncated = false;
        $hasAny = false;

        foreach (self::QUEUES as $tab) {
            $stored = Cache::get($this->queueCacheKey($context, $tab));
            if (is_array($stored)) {
                $parts[$tab] = $stored;
                $truncated = $truncated || (bool) ($stored['truncated'] ?? false);
                $hasAny = true;
            }
        }

        if ($hasAny) {
            return $this->assembleDashboardPayload($context, $parts, $truncated);
        }

        $last = Cache::get($context['last_good_key']);
        if (is_array($last)) {
            return array_merge($this->assembleDashboardPayload($context, [], false), $last, [
                'stale' => true,
                'message' => 'Showing last saved counts.',
            ]);
        }

        return $this->assembleDashboardPayload($context, [], false);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function respondForQueue(array $context, string $queue, bool $forceRefresh): array
    {
        $tab = strtolower(trim($queue));
        if (! in_array($tab, self::QUEUES, true)) {
            throw new RuntimeException('Invalid queue.');
        }

        $cacheKey = $this->queueCacheKey($context, $tab);
        $accountId = (int) ($context['client_account_id'] ?? 0);
        if ($forceRefresh) {
            Cache::forget($cacheKey);
            if ($accountId > 0) {
                $this->dispatchIndexSyncJob($accountId, $tab);
            }
        }

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! $forceRefresh && $this->cacheIsFresh($cached)) {
            return $this->formatQueueResponse($context, $tab, $cached);
        }

        try {
            $result = $this->countTab($context, $tab, false);
            $stored = [
                'count' => $result['count'],
                'truncated' => $result['truncated'],
                'cached_at' => now()->toIso8601String(),
                'refresh_pending' => $forceRefresh,
            ];
            Cache::put($cacheKey, $stored, now()->addMinutes(self::CACHE_TTL_MINUTES));
            $this->touchAggregateLastGood($context, $tab, $stored);

            return $this->formatQueueResponse($context, $tab, $stored);
        } catch (Throwable $e) {
            report($e);
            if (is_array($cached)) {
                return $this->formatQueueResponse($context, $tab, array_merge($cached, [
                    'stale' => true,
                ]));
            }

            throw $e;
        }
    }

    /**
     * Used by artisan portal:refresh-queue-counts (all queues).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildAllQueues(array $context): array
    {
        $parts = [];
        $truncated = false;
        foreach (self::QUEUES as $tab) {
            $result = $this->countTab($context, $tab, true);
            $stored = [
                'count' => $result['count'],
                'truncated' => $result['truncated'],
                'cached_at' => now()->toIso8601String(),
            ];
            Cache::put($this->queueCacheKey($context, $tab), $stored, now()->addMinutes(self::CACHE_TTL_MINUTES));
            $parts[$tab] = $stored;
            $truncated = $truncated || $result['truncated'];
        }

        $payload = $this->assembleDashboardPayload($context, $parts, $truncated);
        Cache::put($context['last_good_key'], $payload, now()->addDay());

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{count: int, truncated: bool}
     */
    private function countTab(array $context, string $tab, bool $background = false): array
    {
        $fromIndex = $this->countTabFromIndex($context, $tab);
        if ($fromIndex !== null) {
            return $fromIndex;
        }

        if (! $background && $this->queueIndexTableAvailable()) {
            $accountId = (int) ($context['client_account_id'] ?? 0);
            if ($accountId > 0 && in_array($tab, self::QUEUES, true)) {
                $this->dispatchIndexSyncJob($accountId, $tab);

                return ['count' => 0, 'truncated' => false];
            }
        }

        $from = $context['open_from'];
        $to = $context['open_to'];
        $maxPages = self::MAX_PAGES_PER_TAB;
        $deadlineSeconds = self::PER_TAB_SECONDS;
        if ($tab === 'awaiting') {
            $from = $context['awaiting_from'];
            $to = $context['awaiting_to'];
        } elseif ($tab === 'shipped') {
            $from = $context['shipped_from'];
            $to = $context['shipped_to'];
            $maxPages = $background ? self::SHIPPED_MAX_PAGES_BACKGROUND : self::SHIPPED_MAX_PAGES;
            $deadlineSeconds = self::SHIPPED_TAB_SECONDS;

            return $this->orders->countShipments([
                'customer_account_id' => $context['customer_id'],
                'date_from' => $from,
                'date_to' => $to,
                'timezone' => $context['timezone'] ?? self::DEFAULT_ACCOUNT_TIMEZONE,
                'max_pages' => $maxPages,
                'count_deadline' => $background ? null : microtime(true) + $deadlineSeconds,
            ]);
        }

        return $this->orders->countOrders([
            'customer_account_id' => $context['customer_id'],
            'tab' => $tab,
            'order_date_from' => $from,
            'order_date_to' => $to,
            'max_pages' => $maxPages,
            'count_deadline' => microtime(true) + $deadlineSeconds,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{count: int, truncated: bool}|null
     */
    public function countTabFromIndex(array $context, string $tab): ?array
    {
        $accountId = (int) ($context['client_account_id'] ?? 0);
        if ($accountId <= 0 || ! in_array($tab, self::QUEUES, true)) {
            return null;
        }

        try {
            if (! Schema::hasTable('shiphero_order_queue_index')) {
                return null;
            }

            $baseQuery = ShipHeroOrderQueueIndex::query()
                ->where('client_account_id', $accountId)
                ->where('queue_kind', $tab);

            if (! (clone $baseQuery)->exists()) {
                return null;
            }

            $query = clone $baseQuery;

            if ($tab === 'on_hold') {
                $query->where('has_backorder', false);
            }

            if ($tab === 'awaiting') {
                $from = $this->parseTimestamp($context['awaiting_from'] ?? null);
                $to = $this->parseTimestamp($context['awaiting_to'] ?? null);
                if ($from !== null) {
                    $query->where('order_date', '>=', $from);
                }
                if ($to !== null) {
                    $query->where('order_date', '<=', $to);
                }
            } elseif ($tab === 'shipped') {
                $from = $this->parseTimestamp($context['shipped_from'] ?? null);
                $to = $this->parseTimestamp($context['shipped_to'] ?? null);
                if ($from !== null) {
                    $query->where('ship_date', '>=', $from);
                }
                if ($to !== null) {
                    $query->where('ship_date', '<=', $to);
                }

                return [
                    'count' => $this->sumShippedLabelCountFromRows($query->get(['list_payload'])),
                    'truncated' => false,
                ];
            } else {
                $from = $this->parseTimestamp($context['open_from'] ?? null);
                $to = $this->parseTimestamp($context['open_to'] ?? null);
                if ($from !== null) {
                    $query->where('order_date', '>=', $from);
                }
                if ($to !== null) {
                    $query->where('order_date', '<=', $to);
                }
            }

            return [
                'count' => (int) $query->distinct()->count('shiphero_order_id'),
                'truncated' => false,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function queueCacheKey(array $context, string $tab): string
    {
        return sprintf(
            'orders:queue_counts:v11:%d:%s:%s',
            (int) $context['client_account_id'],
            $tab,
            md5(implode('|', [
                $context['customer_id'],
                $context['timezone'] ?? self::DEFAULT_ACCOUNT_TIMEZONE,
                $context['awaiting_from'],
                $context['awaiting_to'],
                $context['open_from'],
                $context['open_to'],
                $context['shipped_from'],
                $context['shipped_to'],
            ]))
        );
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function formatQueueResponse(array $context, string $tab, array $stored): array
    {
        $count = (int) ($stored['count'] ?? 0);
        $truncated = (bool) ($stored['truncated'] ?? false);
        $stale = (bool) ($stored['stale'] ?? false);

        $payload = [
            'queue' => $tab,
            'count' => $count,
            'truncated' => $truncated,
            'stale' => $stale,
            'refresh_pending' => (bool) ($stored['refresh_pending'] ?? false),
            'shiphero_ready' => true,
            'message' => $stale
                ? 'Showing last saved count for this queue.'
                : (($stored['refresh_pending'] ?? false) ? 'Syncing latest counts from ShipHero.' : ''),
            'ready_to_ship' => $tab === 'awaiting' ? $count : 0,
            'on_hold' => $tab === 'on_hold' ? $count : 0,
            'backorder' => $tab === 'backorder' ? $count : 0,
            'shipped' => $tab === 'shipped' ? $count : 0,
            'awaiting_order_date_from' => $context['awaiting_from'],
            'awaiting_order_date_to' => $context['awaiting_to'],
            'open_queue_order_date_from' => $context['open_from'],
            'open_queue_order_date_to' => $context['open_to'],
            'shipped_order_date_from' => $context['shipped_from'],
            'shipped_order_date_to' => $context['shipped_to'],
            'cached_at' => $stored['cached_at'] ?? now()->toIso8601String(),
        ];

        return $payload;
    }

    /**
     * @param  array<string, array{count: int, truncated: bool, cached_at: string}>  $parts
     * @return array<string, mixed>
     */
    private function assembleDashboardPayload(array $context, array $parts, bool $truncated): array
    {
        return [
            'ready_to_ship' => (int) ($parts['awaiting']['count'] ?? 0),
            'on_hold' => (int) ($parts['on_hold']['count'] ?? 0),
            'backorder' => (int) ($parts['backorder']['count'] ?? 0),
            'shipped' => (int) ($parts['shipped']['count'] ?? 0),
            'truncated' => $truncated,
            'stale' => false,
            'refresh_pending' => false,
            'shiphero_ready' => true,
            'message' => '',
            'awaiting_order_date_from' => $context['awaiting_from'],
            'awaiting_order_date_to' => $context['awaiting_to'],
            'open_queue_order_date_from' => $context['open_from'],
            'open_queue_order_date_to' => $context['open_to'],
            'shipped_order_date_from' => $context['shipped_from'],
            'shipped_order_date_to' => $context['shipped_to'],
            'cached_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildAllQueuesFromIndex(array $context): array
    {
        $parts = [];
        foreach (self::QUEUES as $tab) {
            $fromIndex = $this->countTabFromIndex($context, $tab);
            $parts[$tab] = [
                'count' => (int) ($fromIndex['count'] ?? 0),
                'truncated' => (bool) ($fromIndex['truncated'] ?? false),
                'cached_at' => now()->toIso8601String(),
            ];
        }

        $payload = $this->assembleDashboardPayload($context, $parts, false);
        $accountId = (int) ($context['client_account_id'] ?? 0);
        $payload['revision'] = $this->getCountsRevision($accountId);
        $payload['from_index'] = true;

        return $payload;
    }

    public function getCountsRevision(int $clientAccountId): int
    {
        if ($clientAccountId <= 0) {
            return 0;
        }

        return max(0, (int) Cache::get($this->countsRevisionKey($clientAccountId), 0));
    }

    public function bumpCountsRevision(int $clientAccountId): int
    {
        if ($clientAccountId <= 0) {
            return 0;
        }

        $key = $this->countsRevisionKey($clientAccountId);
        if (! Cache::has($key)) {
            Cache::put($key, 1, now()->addDays(30));

            return 1;
        }

        $next = (int) Cache::increment($key);
        Cache::put($key, $next, now()->addDays(30));

        return $next;
    }

    /**
     * @param  list<string>  $tabs
     */
    public function refreshQueueCacheFromIndex(int $clientAccountId, array $tabs): void
    {
        if ($clientAccountId <= 0 || $tabs === []) {
            return;
        }

        $account = ClientAccount::query()->find($clientAccountId);
        if ($account === null) {
            return;
        }

        $context = $this->contextForAccount($account);
        foreach ($tabs as $tab) {
            $tab = strtolower(trim($tab));
            if (! in_array($tab, self::QUEUES, true)) {
                continue;
            }

            $fromIndex = $this->countTabFromIndex($context, $tab);
            $stored = [
                'count' => (int) ($fromIndex['count'] ?? 0),
                'truncated' => (bool) ($fromIndex['truncated'] ?? false),
                'cached_at' => now()->toIso8601String(),
            ];
            Cache::put($this->queueCacheKey($context, $tab), $stored, now()->addMinutes(self::CACHE_TTL_MINUTES));
            $this->touchAggregateLastGood($context, $tab, $stored);
        }
    }

    private function countsRevisionKey(int $clientAccountId): string
    {
        return 'orders:queue_counts:revision:'.$clientAccountId;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array{count: int, truncated: bool, cached_at: string}  $stored
     */
    private function touchAggregateLastGood(array $context, string $tab, array $stored): void
    {
        $last = Cache::get($context['last_good_key'], []);
        if (! is_array($last)) {
            $last = $this->assembleDashboardPayload($context, [], false);
        }

        $field = $this->dashboardFieldForTab($tab);
        $last[$field] = (int) $stored['count'];
        $last['truncated'] = (bool) ($last['truncated'] ?? false) || (bool) $stored['truncated'];
        $last['cached_at'] = now()->toIso8601String();
        Cache::put($context['last_good_key'], $last, now()->addDay());
    }

    private function dashboardFieldForTab(string $tab): string
    {
        if ($tab === 'awaiting') {
            return 'ready_to_ship';
        }

        return $tab;
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function cacheIsFresh(array $cached): bool
    {
        $at = $cached['cached_at'] ?? null;
        if (! is_string($at) || trim($at) === '') {
            return false;
        }

        try {
            return Carbon::parse($at)->greaterThan(now()->subMinutes(self::CACHE_TTL_MINUTES));
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function accountTimezone(ClientAccount $account): string
    {
        $tz = trim((string) ($account->timezone ?? ''));
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            return $tz;
        }

        return self::DEFAULT_ACCOUNT_TIMEZONE;
    }

    private function queueIndexTableAvailable(): bool
    {
        try {
            return Schema::hasTable('shiphero_order_queue_index');
        } catch (Throwable $e) {
            return false;
        }
    }

    private function dispatchIndexSyncJob(int $accountId, string $tab): void
    {
        if ($accountId <= 0 || ! in_array($tab, self::QUEUES, true)) {
            return;
        }

        $lockKey = sprintf('order_queue_sync_dispatch:%d:%s', $accountId, $tab);
        if (Cache::has($lockKey)) {
            return;
        }

        Cache::put($lockKey, 1, now()->addMinutes(2));
        PatchHomeDashboardAccountJob::dispatchAfterHttp($accountId, $tab);
    }

    private function dateStartIso(?string $value, string $timezone): string
    {
        if ($value === null || trim($value) === '') {
            return Carbon::now($timezone)->startOfDay()->toIso8601String();
        }

        return Carbon::parse(trim($value), $timezone)->startOfDay()->toIso8601String();
    }

    private function dateEndIso(?string $value, string $timezone): string
    {
        if ($value === null || trim($value) === '') {
            return Carbon::now($timezone)->endOfDay()->toIso8601String();
        }

        return Carbon::parse(trim($value), $timezone)->endOfDay()->toIso8601String();
    }

    private function parseTimestamp($value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param  iterable<\App\Models\ShipHeroOrderQueueIndex|object{list_payload?: mixed}>  $rows
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
}
