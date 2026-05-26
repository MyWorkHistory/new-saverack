<?php

namespace App\Services;

use App\Models\ClientAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Portal dashboard order queue totals. Never blocks HTTP on full ShipHero scans.
 */
class PortalQueueCountsService
{
    private const CACHE_TTL_MINUTES = 10;

    private const PER_TAB_SECONDS = 8;

    private const MAX_PAGES_PER_TAB = 4;

    /** @var ShipHeroOrderService */
    private $orders;

    public function __construct(ShipHeroOrderService $orders)
    {
        $this->orders = $orders;
    }

    /**
     * @param  array{order_date_from?: string|null, order_date_to?: string|null}  $validated
     * @return array{
     *   client_account_id: int,
     *   customer_id: string,
     *   cache_key: string,
     *   last_good_key: string,
     *   lock_key: string,
     *   awaiting_from: string,
     *   awaiting_to: string,
     *   open_from: string,
     *   open_to: string,
     *   shipped_from: string,
     *   shipped_to: string
     * }
     */
    public function contextForAccount(ClientAccount $account, array $validated = []): array
    {
        $clientAccountId = (int) $account->id;
        $customerId = trim((string) $account->shiphero_customer_account_id);

        $now = Carbon::now();
        $awaitingFrom = $this->dateStartIso($now->copy()->subDays(6)->toDateString());
        $awaitingTo = $this->dateEndIso($now->copy()->toDateString());
        $openFrom = $this->dateStartIso($now->copy()->toDateString());
        $openTo = $this->dateEndIso($now->copy()->toDateString());

        $shippedFromInput = $validated['order_date_from'] ?? null;
        $shippedToInput = $validated['order_date_to'] ?? null;
        if ($shippedFromInput !== null && $shippedToInput !== null) {
            $shippedFrom = $this->dateStartIso((string) $shippedFromInput);
            $shippedTo = $this->dateEndIso((string) $shippedToInput);
        } else {
            $shippedFrom = $openFrom;
            $shippedTo = $openTo;
        }

        $cacheKey = sprintf(
            'orders:queue_counts:v4:%d:%s',
            $clientAccountId,
            md5(implode('|', array_filter([
                $customerId,
                $awaitingFrom,
                $awaitingTo,
                $openFrom,
                $openTo,
                $shippedFrom,
                $shippedTo,
            ])))
        );

        return [
            'client_account_id' => $clientAccountId,
            'customer_id' => $customerId,
            'cache_key' => $cacheKey,
            'last_good_key' => 'orders:queue_counts:last:'.$clientAccountId,
            'lock_key' => 'orders:queue_counts:lock:'.$clientAccountId,
            'awaiting_from' => $awaitingFrom,
            'awaiting_to' => $awaitingTo,
            'open_from' => $openFrom,
            'open_to' => $openTo,
            'shipped_from' => $shippedFrom,
            'shipped_to' => $shippedTo,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function respond(array $context, bool $forceRefresh): array
    {
        if (Cache::has($context['lock_key'])) {
            return $this->withRefreshPending($this->immediate($context), true);
        }

        $cached = Cache::get($context['cache_key']);
        if (is_array($cached) && ! $forceRefresh) {
            return array_merge($cached, ['refresh_pending' => false]);
        }

        if ($forceRefresh) {
            Cache::forget($context['cache_key']);
        }

        $this->queueRebuild($context);

        return $this->withRefreshPending($this->immediate($context), true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function queueRebuild(array $context): void
    {
        if (! Cache::add($context['lock_key'], 1, now()->addMinutes(3))) {
            return;
        }

        $contextCopy = $context;
        dispatch(function () use ($contextCopy): void {
            try {
                $service = app(self::class);
                $payload = $service->buildAndStore($contextCopy);
                $payload['refresh_pending'] = false;
                $payload['stale'] = false;
                $payload['message'] = '';
                Cache::put($contextCopy['cache_key'], $payload, now()->addMinutes(self::CACHE_TTL_MINUTES));
                Cache::put($contextCopy['last_good_key'], $payload, now()->addDay());
            } catch (Throwable $e) {
                report($e);
            } finally {
                Cache::forget($contextCopy['lock_key']);
            }
        })->afterResponse();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildAndStore(array $context): array
    {
        $payload = $this->build($context);
        Cache::put($context['cache_key'], $payload, now()->addMinutes(self::CACHE_TTL_MINUTES));
        Cache::put($context['last_good_key'], $payload, now()->addDay());

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(array $context): array
    {
        $customerId = $context['customer_id'];
        $countBase = [
            'max_pages' => self::MAX_PAGES_PER_TAB,
        ];

        $ready = $this->countTab($countBase, $customerId, 'awaiting', $context['awaiting_from'], $context['awaiting_to']);
        $hold = $this->countTab($countBase, $customerId, 'on_hold', $context['open_from'], $context['open_to']);
        $back = $this->countTab($countBase, $customerId, 'backorder', $context['open_from'], $context['open_to']);
        $ship = $this->countTab($countBase, $customerId, 'shipped', $context['shipped_from'], $context['shipped_to']);

        return [
            'ready_to_ship' => $ready['count'],
            'on_hold' => $hold['count'],
            'backorder' => $back['count'],
            'shipped' => $ship['count'],
            'truncated' => $ready['truncated'] || $hold['truncated'] || $back['truncated'] || $ship['truncated'],
            'shiphero_ready' => true,
            'stale' => false,
            'refresh_pending' => false,
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
     * @param  array<string, mixed>  $countBase
     * @return array{count: int, truncated: bool}
     */
    private function countTab(array $countBase, string $customerId, string $tab, string $from, string $to): array
    {
        return $this->orders->countOrders(array_merge($countBase, [
            'customer_account_id' => $customerId,
            'tab' => $tab,
            'order_date_from' => $from,
            'order_date_to' => $to,
            'count_deadline' => microtime(true) + self::PER_TAB_SECONDS,
        ]));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function immediate(array $context): array
    {
        $cached = Cache::get($context['cache_key']);
        if (is_array($cached)) {
            return $cached;
        }

        $lastGood = Cache::get($context['last_good_key']);
        if (is_array($lastGood)) {
            return array_merge($lastGood, [
                'stale' => true,
                'message' => 'Showing last saved counts while we refresh from ShipHero.',
            ]);
        }

        return $this->placeholder($context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function placeholder(array $context): array
    {
        return [
            'ready_to_ship' => 0,
            'on_hold' => 0,
            'backorder' => 0,
            'shipped' => 0,
            'truncated' => false,
            'shiphero_ready' => true,
            'stale' => false,
            'refresh_pending' => true,
            'message' => 'Loading counts from ShipHero…',
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withRefreshPending(array $payload, bool $pending): array
    {
        $payload['refresh_pending'] = $pending;
        if ($pending && ($payload['message'] ?? '') === '') {
            $payload['message'] = 'Updating counts from ShipHero…';
        }

        return $payload;
    }

    private function dateStartIso(?string $value): string
    {
        return Carbon::parse($value ?? 'today')->startOfDay()->toIso8601String();
    }

    private function dateEndIso(?string $value): string
    {
        return Carbon::parse($value ?? 'today')->endOfDay()->toIso8601String();
    }
}
