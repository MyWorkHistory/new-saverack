<?php

namespace App\Services;

use App\Models\ClientAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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

    /** Shipped is typically larger; give it a bit more time/pages to reduce truncation. */
    private const SHIPPED_TAB_SECONDS = 20;

    private const MAX_PAGES_PER_TAB = 2;

    private const SHIPPED_MAX_PAGES = 4;

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
        $awaitingFrom = $this->dateStartIso($now->copy()->subDays(6)->toDateString(), $timezone);
        $awaitingTo = $this->dateEndIso($now->copy()->toDateString(), $timezone);
        $openFrom = $this->dateStartIso($now->copy()->toDateString(), $timezone);
        $openTo = $this->dateEndIso($now->copy()->toDateString(), $timezone);

        $shippedFromInput = $validated['order_date_from'] ?? null;
        $shippedToInput = $validated['order_date_to'] ?? null;
        if ($shippedFromInput !== null && $shippedToInput !== null) {
            $shippedFrom = $this->dateStartIso((string) $shippedFromInput, $timezone);
            $shippedTo = $this->dateEndIso((string) $shippedToInput, $timezone);
        } else {
            $shippedFrom = $openFrom;
            $shippedTo = $openTo;
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
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! $forceRefresh && $this->cacheIsFresh($cached)) {
            return $this->formatQueueResponse($context, $tab, $cached);
        }

        try {
            $result = $this->countTab($context, $tab);
            $stored = [
                'count' => $result['count'],
                'truncated' => $result['truncated'],
                'cached_at' => now()->toIso8601String(),
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
            $result = $this->countTab($context, $tab);
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
    private function countTab(array $context, string $tab): array
    {
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
            $maxPages = self::SHIPPED_MAX_PAGES;
            $deadlineSeconds = self::SHIPPED_TAB_SECONDS;
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
     */
    private function queueCacheKey(array $context, string $tab): string
    {
        return sprintf(
            'orders:queue_counts:v8:%d:%s:%s',
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
            'refresh_pending' => false,
            'shiphero_ready' => true,
            'message' => $stale ? 'Showing last saved count for this queue.' : '',
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
}
