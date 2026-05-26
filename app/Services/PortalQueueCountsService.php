<?php

namespace App\Services;

use App\Models\ClientAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Portal dashboard order queue totals (cached, synchronous build).
 */
class PortalQueueCountsService
{
    private const CACHE_TTL_MINUTES = 10;

    private const PER_TAB_SECONDS = 6;

    private const MAX_PAGES_PER_TAB = 3;

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
            'orders:queue_counts:v5:%d:%s',
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
        // Clear legacy locks from the old afterResponse flow (PHP-FPM often never ran them).
        Cache::forget('orders:queue_counts:lock:'.$context['client_account_id']);

        if ($forceRefresh) {
            Cache::forget($context['cache_key']);
        }

        $cached = Cache::get($context['cache_key']);
        if (is_array($cached) && ! $forceRefresh) {
            return array_merge($cached, [
                'refresh_pending' => false,
                'stale' => false,
                'message' => '',
            ]);
        }

        try {
            return Cache::remember($context['cache_key'], now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($context) {
                $payload = $this->build($context);
                Cache::put($context['last_good_key'], $payload, now()->addDay());

                return $payload;
            });
        } catch (Throwable $e) {
            report($e);
            $lastGood = Cache::get($context['last_good_key']);
            if (is_array($lastGood)) {
                return array_merge($lastGood, [
                    'stale' => true,
                    'refresh_pending' => false,
                    'message' => 'Showing last saved counts. ShipHero is slow or unavailable — try Refresh again.',
                ]);
            }

            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException('Could not load order counts from ShipHero.', 0, $e);
        }
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

    private function dateStartIso(?string $value): string
    {
        return Carbon::parse($value ?? 'today')->startOfDay()->toIso8601String();
    }

    private function dateEndIso(?string $value): string
    {
        return Carbon::parse($value ?? 'today')->endOfDay()->toIso8601String();
    }
}
