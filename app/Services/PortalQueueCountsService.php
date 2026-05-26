<?php

namespace App\Services;

use App\Models\ClientAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Portal dashboard order queue totals.
 *
 * HTTP handlers must return immediately; ShipHero scans run via portal:refresh-queue-counts (CLI).
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
            'orders:queue_counts:v6:%d:%s',
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
     * Instant JSON payload for the portal API. Never calls ShipHero.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function respond(array $context, bool $forceRefresh): array
    {
        Cache::forget($context['lock_key']);

        if ($forceRefresh) {
            Cache::forget($context['cache_key']);
        }

        $cached = Cache::get($context['cache_key']);
        if (is_array($cached) && ! $forceRefresh && $this->cacheIsFresh($cached)) {
            return array_merge($cached, [
                'refresh_pending' => false,
                'stale' => false,
                'message' => '',
            ]);
        }

        $needsRebuild = ! is_array($cached) || $forceRefresh || ! $this->cacheIsFresh($cached);
        if ($needsRebuild) {
            $this->spawnRebuild($context);
        }

        $lastGood = Cache::get($context['last_good_key']);
        if (is_array($lastGood)) {
            return array_merge($lastGood, [
                'refresh_pending' => $needsRebuild,
                'stale' => $needsRebuild,
                'message' => $needsRebuild ? 'Updating counts from ShipHero…' : '',
            ]);
        }

        return $this->placeholder($context, $needsRebuild);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function spawnRebuild(array $context): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        if (! Cache::add($context['lock_key'], 1, now()->addMinutes(5))) {
            return;
        }

        $accountId = (int) $context['client_account_id'];
        $php = defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== ''
            ? PHP_BINARY
            : 'php';
        $artisan = base_path('artisan');

        if (DIRECTORY_SEPARATOR === '\\') {
            $inner = escapeshellarg($php).' '.escapeshellarg($artisan)
                .' portal:refresh-queue-counts '.(string) $accountId;
            $cmd = 'cmd /C start /B "" '.$inner.' > NUL 2>&1';
            @pclose(@popen($cmd, 'r'));
            Log::info('portal.queue_counts.spawned', ['client_account_id' => $accountId, 'shell' => 'windows']);

            return;
        }

        $cmd = escapeshellarg($php).' '.escapeshellarg($artisan)
            .' portal:refresh-queue-counts '.(string) $accountId
            .' > /dev/null 2>&1 &';
        exec($cmd);
        Log::info('portal.queue_counts.spawned', ['client_account_id' => $accountId, 'shell' => 'posix']);
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
    private function placeholder(array $context, bool $pending): array
    {
        return [
            'ready_to_ship' => 0,
            'on_hold' => 0,
            'backorder' => 0,
            'shipped' => 0,
            'truncated' => false,
            'shiphero_ready' => true,
            'stale' => false,
            'refresh_pending' => $pending,
            'message' => $pending ? 'Updating counts from ShipHero…' : '',
            'awaiting_order_date_from' => $context['awaiting_from'],
            'awaiting_order_date_to' => $context['awaiting_to'],
            'open_queue_order_date_from' => $context['open_from'],
            'open_queue_order_date_to' => $context['open_to'],
            'shipped_order_date_from' => $context['shipped_from'],
            'shipped_order_date_to' => $context['shipped_to'],
            'cached_at' => now()->toIso8601String(),
        ];
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
