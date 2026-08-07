<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\ShipHeroOrderQueueIndex;
use App\Services\ShipHeroOrderDetailCacheService;
use App\Services\ShipHeroOrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackfillOrderDetailsCommand extends Command
{
    public const CURSOR_CACHE_KEY = 'shiphero:order_detail_backfill:cursor';

    public const LAST_RUN_CACHE_KEY = 'shiphero:schedule:last_run:orders_backfill_order_details';

    protected $signature = 'orders:backfill-order-details
        {--from=2026-01-01 : Start date YYYY-MM-DD (queue index order/ship/last_seen)}
        {--to= : End date YYYY-MM-DD; default today}
        {--account= : Optional client account id}
        {--sleep=2 : Seconds to pause between ShipHero getOrder calls}
        {--limit=200 : Max orders to fetch this run (0 = no limit)}
        {--force : Re-fetch even when detail cache already exists}
        {--reset-cursor : Ignore saved resume cursor and start from the beginning}';

    protected $description = 'Backfill ShipHero order detail cache from the local queue index (throttled)';

    public function handle(
        ShipHeroOrderService $orders,
        ShipHeroOrderDetailCacheService $detailCache
    ): int {
        $fromDate = trim((string) $this->option('from'));
        if ($fromDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            $this->error('--from must be YYYY-MM-DD.');

            return self::FAILURE;
        }

        $toOverride = trim((string) $this->option('to'));
        $toDate = $toOverride !== '' ? $toOverride : Carbon::now()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $this->error('--to must be YYYY-MM-DD.');

            return self::FAILURE;
        }

        $fromStart = Carbon::parse($fromDate)->startOfDay();
        $toEnd = Carbon::parse($toDate)->endOfDay();
        if ($toEnd->lt($fromStart)) {
            $this->error('--to must be on or after --from.');

            return self::FAILURE;
        }

        $sleepSeconds = max(0, (int) $this->option('sleep'));
        $limit = max(0, (int) $this->option('limit'));
        $force = (bool) $this->option('force');
        $resetCursor = (bool) $this->option('reset-cursor');

        $accountOpt = trim((string) $this->option('account'));
        $accountIds = null;
        if ($accountOpt !== '') {
            $account = ClientAccount::query()->find((int) $accountOpt);
            if ($account === null) {
                $this->error('Account not found.');

                return self::FAILURE;
            }
            $accountIds = [(int) $account->id];
        }

        if ($resetCursor) {
            Cache::forget(self::CURSOR_CACHE_KEY);
            $this->info('Resume cursor cleared.');
        }

        $cursor = (int) Cache::get(self::CURSOR_CACHE_KEY, 0);

        $this->info(sprintf(
            'Backfilling order details %s → %s (cursor id > %d, limit %s, sleep %ds)%s',
            $fromDate,
            $toDate,
            $cursor,
            $limit > 0 ? (string) $limit : 'none',
            $sleepSeconds,
            $force ? ', force' : ''
        ));

        $fetched = 0;
        $skipped = 0;
        $failed = 0;
        $lastId = $cursor;

        $query = ShipHeroOrderQueueIndex::query()
            ->select(['id', 'client_account_id', 'shiphero_order_id'])
            ->where('id', '>', $cursor)
            ->where(function ($q) use ($fromStart, $toEnd) {
                $q->whereBetween('order_date', [$fromStart, $toEnd])
                    ->orWhereBetween('ship_date', [$fromStart, $toEnd])
                    ->orWhereBetween('last_seen_at', [$fromStart, $toEnd]);
            })
            ->orderBy('id');

        if (is_array($accountIds)) {
            $query->whereIn('client_account_id', $accountIds);
        }

        $customerIdByAccount = [];
        $seenPairs = [];

        foreach ($query->cursor() as $row) {
            $indexId = (int) ($row->id ?? 0);
            $clientAccountId = (int) ($row->client_account_id ?? 0);
            $orderId = trim((string) ($row->shiphero_order_id ?? ''));
            $lastId = max($lastId, $indexId);

            if ($clientAccountId <= 0 || $orderId === '') {
                $skipped++;
                continue;
            }

            $pairKey = $clientAccountId.'|'.$orderId;
            if (isset($seenPairs[$pairKey])) {
                continue;
            }
            $seenPairs[$pairKey] = true;

            if (! $force && $detailCache->hasCachedOrder($clientAccountId, $orderId)) {
                $skipped++;
                continue;
            }

            if (! array_key_exists($clientAccountId, $customerIdByAccount)) {
                $account = ClientAccount::query()->find($clientAccountId);
                $customerIdByAccount[$clientAccountId] = $account !== null
                    ? trim((string) $account->shiphero_customer_account_id)
                    : '';
            }
            $customerId = $customerIdByAccount[$clientAccountId];
            if ($customerId === '') {
                $skipped++;
                continue;
            }

            try {
                $order = $orders->getOrder($orderId, $customerId);
                $detailCache->putOrder($clientAccountId, $orderId, $order);
                $fetched++;
                $this->line(sprintf(
                    '  cached account #%d order %s',
                    $clientAccountId,
                    $orderId
                ));
            } catch (Throwable $e) {
                $failed++;
                $this->warn(sprintf(
                    '  failed account #%d order %s: %s',
                    $clientAccountId,
                    $orderId,
                    $e->getMessage()
                ));
                Log::warning('orders.backfill_order_details.failed', [
                    'client_account_id' => $clientAccountId,
                    'shiphero_order_id' => $orderId,
                    'message' => $e->getMessage(),
                ]);
            }

            if ($sleepSeconds > 0) {
                sleep($sleepSeconds);
            }

            if ($limit > 0 && $fetched >= $limit) {
                break;
            }
        }

        Cache::put(self::CURSOR_CACHE_KEY, $lastId, now()->addDays(30));
        Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addDays(7));

        $this->info(sprintf(
            'Done. fetched=%d skipped=%d failed=%d cursor=%d',
            $fetched,
            $skipped,
            $failed,
            $lastId
        ));

        return $failed > 0 && $fetched === 0 ? self::FAILURE : self::SUCCESS;
    }
}
