<?php

namespace App\Services;

use App\Jobs\RefreshPortalQueueCountsJob;
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
    public const PENDING_REBUILDS_CACHE_KEY = 'orders:queue_counts:pending';

    private const CACHE_TTL_MINUTES = 10;

    private const PER_TAB_SECONDS = 4;

    private const MAX_PAGES_PER_TAB = 2;

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
     * Portal API response for dashboard counts.
     * Builds synchronously with strict limits to avoid endless pending states.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function respond(array $context, bool $forceRefresh): array
    {
        // Clear stale lock values left by old async flow.
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

        $lastGood = Cache::get($context['last_good_key']);
        $lockKey = (string) $context['lock_key'];
        if (! Cache::add($lockKey, 1, now()->addMinutes(2))) {
            if (is_array($lastGood)) {
                return array_merge($lastGood, [
                    'refresh_pending' => true,
                    'stale' => true,
                    'message' => 'Updating counts from ShipHero…',
                ]);
            }

            return $this->placeholder($context, true);
        }

        try {
            $payload = $this->buildAndStore($context);

            return array_merge($payload, [
                'refresh_pending' => false,
                'stale' => false,
                'message' => '',
            ]);
        } catch (\Throwable $e) {
            report($e);
            if (is_array($lastGood)) {
                return array_merge($lastGood, [
                    'refresh_pending' => false,
                    'stale' => true,
                    'message' => 'Showing last saved counts. ShipHero is slow or unavailable — try Refresh again.',
                ]);
            }

            throw $e;
        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Queue a background rebuild (no ShipHero in the web request).
     *
     * @param  array<string, mixed>  $context
     */
    public function spawnRebuild(array $context): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $accountId = (int) $context['client_account_id'];
        $this->enqueuePendingRebuild($accountId);

        if ($this->tryDispatchQueueJob($accountId)) {
            return;
        }

        if ($this->trySpawnShellProcess($accountId)) {
            return;
        }

        Log::info('portal.queue_counts.queued_for_cron', [
            'client_account_id' => $accountId,
            'hint' => 'Run: php artisan portal:refresh-pending-queue-counts (or enable Laravel queue worker)',
        ]);
    }

    public function enqueuePendingRebuild(int $clientAccountId): void
    {
        $list = Cache::get(self::PENDING_REBUILDS_CACHE_KEY, []);
        if (! is_array($list)) {
            $list = [];
        }
        $list[(string) $clientAccountId] = now()->timestamp;
        Cache::put(self::PENDING_REBUILDS_CACHE_KEY, $list, now()->addDay());
    }

    public function dequeuePendingRebuild(int $clientAccountId): void
    {
        $list = Cache::get(self::PENDING_REBUILDS_CACHE_KEY, []);
        if (! is_array($list)) {
            return;
        }
        unset($list[(string) $clientAccountId]);
        Cache::put(self::PENDING_REBUILDS_CACHE_KEY, $list, now()->addDay());
    }

    public function processPendingRebuilds(): int
    {
        $list = Cache::get(self::PENDING_REBUILDS_CACHE_KEY, []);
        if (! is_array($list) || $list === []) {
            return 0;
        }

        $processed = 0;
        foreach (array_keys($list) as $rawId) {
            $accountId = (int) $rawId;
            if ($accountId < 1) {
                continue;
            }

            $lockKey = 'orders:queue_counts:lock:'.$accountId;
            if (! Cache::add($lockKey, 1, now()->addMinutes(5))) {
                continue;
            }

            try {
                $account = ClientAccount::query()->find($accountId);
                if ($account === null) {
                    $this->dequeuePendingRebuild($accountId);
                    continue;
                }
                $sid = trim((string) ($account->shiphero_customer_account_id ?? ''));
                if ($sid === '') {
                    $this->dequeuePendingRebuild($accountId);
                    continue;
                }

                $context = $this->contextForAccount($account);
                $this->buildAndStore($context);
                $this->dequeuePendingRebuild($accountId);
                $processed++;
            } catch (\Throwable $e) {
                report($e);
            } finally {
                Cache::forget($lockKey);
            }
        }

        return $processed;
    }

    private function tryDispatchQueueJob(int $accountId): bool
    {
        $driver = (string) config('queue.default', 'sync');
        if ($driver === 'sync') {
            return false;
        }

        try {
            RefreshPortalQueueCountsJob::dispatch($accountId);

            return true;
        } catch (\Throwable $e) {
            Log::warning('portal.queue_counts.queue_dispatch_failed', [
                'client_account_id' => $accountId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function trySpawnShellProcess(int $accountId): bool
    {
        if (! $this->shellFunctionsAvailable()) {
            return false;
        }

        $php = defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== ''
            ? PHP_BINARY
            : 'php';
        $artisan = base_path('artisan');

        try {
            if (DIRECTORY_SEPARATOR === '\\') {
                $inner = escapeshellarg($php).' '.escapeshellarg($artisan)
                    .' portal:refresh-queue-counts '.(string) $accountId;
                $cmd = 'cmd /C start /B "" '.$inner.' > NUL 2>&1';
                $handle = @popen($cmd, 'r');
                if (is_resource($handle)) {
                    @pclose($handle);
                    Log::info('portal.queue_counts.spawned', ['client_account_id' => $accountId, 'shell' => 'windows']);

                    return true;
                }

                return false;
            }

            $cmd = escapeshellarg($php).' '.escapeshellarg($artisan)
                .' portal:refresh-queue-counts '.(string) $accountId
                .' > /dev/null 2>&1 &';
            @exec($cmd);
            Log::info('portal.queue_counts.spawned', ['client_account_id' => $accountId, 'shell' => 'posix']);

            return true;
        } catch (\Throwable $e) {
            Log::warning('portal.queue_counts.shell_spawn_failed', [
                'client_account_id' => $accountId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function shellFunctionsAvailable(): bool
    {
        if ($this->isPhpFunctionDisabled('exec')
            && $this->isPhpFunctionDisabled('proc_open')
            && $this->isPhpFunctionDisabled('popen')) {
            return false;
        }

        return function_exists('exec') || function_exists('proc_open') || function_exists('popen');
    }

    private function isPhpFunctionDisabled(string $function): bool
    {
        $raw = strtolower((string) ini_get('disable_functions'));
        if ($raw === '') {
            return false;
        }

        return in_array(strtolower($function), array_map('trim', explode(',', $raw)), true);
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
