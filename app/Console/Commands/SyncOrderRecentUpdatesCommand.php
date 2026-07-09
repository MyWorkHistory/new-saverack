<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Services\OrderDashboardSnapshotService;
use App\Services\PortalQueueCountsService;
use App\Services\ShipHeroOrderDetailCacheService;
use App\Services\ShipHeroOrderQueueIndexService;
use App\Services\ShipHeroOrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncOrderRecentUpdatesCommand extends Command
{
    protected $signature = 'orders:sync-recent-updates
        {--minutes=15 : Look back window for updated orders}
        {--account= : Optional client account id}';

    protected $description = 'Reconcile recently updated ShipHero orders into the local queue index (webhook fallback)';

    public function handle(
        ShipHeroOrderService $orders,
        ShipHeroOrderQueueIndexService $index,
        OrderDashboardSnapshotService $snapshots,
        PortalQueueCountsService $queueCounts,
        ShipHeroOrderDetailCacheService $detailCache
    ): int {
        $minutes = max(5, min(120, (int) $this->option('minutes')));
        $updatedFrom = Carbon::now('UTC')->subMinutes($minutes)->toIso8601String();

        $query = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('id');

        $accountOpt = trim((string) $this->option('account'));
        if ($accountOpt !== '') {
            $query->where('id', (int) $accountOpt);
        }

        $accounts = $query->get(['id', 'shiphero_customer_account_id', 'company_name']);
        if ($accounts->isEmpty()) {
            $this->warn('No linked client accounts.');

            return self::SUCCESS;
        }

        $reconciled = 0;
        foreach ($accounts as $account) {
            $clientAccountId = (int) $account->id;
            $customerId = trim((string) $account->shiphero_customer_account_id);
            if ($customerId === '') {
                continue;
            }

            $this->line('Scanning account #'.$clientAccountId.' ('.$account->company_name.')…');
            $after = null;
            $pages = 0;
            $affectedTabs = [];

            do {
                try {
                    $page = $orders->listRecentlyUpdatedOrders([
                        'customer_account_id' => $customerId,
                        'updated_from' => $updatedFrom,
                        'first' => 50,
                        'after' => $after,
                    ]);
                } catch (Throwable $e) {
                    $this->error('Account #'.$clientAccountId.': '.$e->getMessage());
                    Log::warning('orders.sync_recent_updates.failed', [
                        'client_account_id' => $clientAccountId,
                        'message' => $e->getMessage(),
                    ]);
                    break;
                }

                $rows = is_array($page['rows'] ?? null) ? $page['rows'] : [];
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $orderId = trim((string) ($row['id'] ?? ''));
                    if ($orderId === '') {
                        continue;
                    }
                    $tabs = $index->reconcileOrder($clientAccountId, $orderId);
                    $detailCache->clearOrder($clientAccountId, $orderId);
                    foreach ($tabs as $tab) {
                        $affectedTabs[$tab] = true;
                    }
                    $reconciled++;
                }

                $after = $page['pagination']['end_cursor'] ?? null;
                $hasNext = (bool) ($page['pagination']['has_next_page'] ?? false);
                $pages++;
            } while ($hasNext && $after !== null && $pages < 20);

            $tabs = array_keys($affectedTabs);
            if ($tabs !== []) {
                foreach ($tabs as $tab) {
                    $snapshots->patchAccountFromQueueTab($clientAccountId, $tab);
                }
                $queueCounts->refreshQueueCacheFromIndex($clientAccountId, $tabs);
                $queueCounts->bumpCountsRevision($clientAccountId);
                $snapshots->bumpDashboardRevision();
            }
        }

        $this->info('Reconciled '.$reconciled.' order(s).');
        Cache::put('shiphero:schedule:last_run:orders_sync_recent_updates', now()->toIso8601String(), now()->addDays(7));

        return self::SUCCESS;
    }
}
