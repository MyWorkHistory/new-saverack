<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\OrderDashboardSection;
use App\Models\ShipHeroOrderQueueIndex;
use App\Services\OrderDashboardSnapshotService;
use App\Services\ShipHeroInventoryService;
use App\Services\ShipHeroOrderQueueIndexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class WarmShipHeroDataCommand extends Command
{
    protected $signature = 'crm:warm-shiphero-data
                            {--account= : Optional single client_account id}
                            {--skip-inventory : Skip inventory catalog sync}
                            {--dry-run : List planned work without calling ShipHero}';

    protected $description = 'Warm ShipHero order index, home dashboard, and inventory catalog after DB recovery';

    public function handle(
        ShipHeroOrderQueueIndexService $orderIndex,
        OrderDashboardSnapshotService $dashboard,
        ShipHeroInventoryService $inventory
    ): int {
        $accountOpt = trim((string) $this->option('account'));
        $skipInventory = (bool) $this->option('skip-inventory');
        $dryRun = (bool) $this->option('dry-run');

        $accounts = $this->resolveAccounts($accountOpt);
        if ($accounts === null) {
            return self::FAILURE;
        }

        if ($accounts->isEmpty()) {
            $this->warn('No client accounts with shiphero_customer_account_id found.');

            return self::SUCCESS;
        }

        $this->info('Warming ShipHero data for '.$accounts->count().' account(s)'.($dryRun ? ' (dry run)' : '').'…');
        $this->line('');

        $failures = [];

        if ($dryRun) {
            $this->line('Would sync order queue index for account ids: '.$accounts->pluck('id')->implode(', '));
            $this->line('Would refresh home dashboard sections: all');
            if (! $skipInventory) {
                $this->line('Would dispatch inventory catalog sync for '.$accounts->count().' account(s)');
            }
            $this->printSummary($accounts->count(), null, null, $skipInventory ? 0 : $accounts->count());

            return self::SUCCESS;
        }

        $this->comment('Order queue index');
        foreach ($accounts as $account) {
            $accountId = (int) $account->id;
            $this->line('  Syncing orders for account #'.$accountId.'…');
            foreach (ShipHeroOrderQueueIndex::QUEUE_KINDS as $tab) {
                try {
                    $orderIndex->syncAccountQueue($accountId, $tab);
                } catch (Throwable $e) {
                    $failures[] = 'orders #'.$accountId.' '.$tab.': '.$e->getMessage();
                    $this->warn('    '.$tab.' failed: '.$e->getMessage());
                }
            }
        }

        $this->line('');
        $this->comment('Home dashboard');
        try {
            foreach (OrderDashboardSection::ALL_KEYS as $key) {
                $this->line('  Refreshing '.$key.'…');
                $dashboard->refreshSection($key);
            }
        } catch (Throwable $e) {
            $failures[] = 'dashboard: '.$e->getMessage();
            $this->warn('  Dashboard refresh failed: '.$e->getMessage());
        }

        $inventoryQueued = 0;
        if (! $skipInventory) {
            $this->line('');
            $this->comment('Inventory catalog');
            foreach ($accounts as $account) {
                $customerId = trim((string) $account->shiphero_customer_account_id);
                if ($customerId === '') {
                    continue;
                }
                try {
                    $inventory->dispatchCatalogSyncJob(
                        (int) $account->id,
                        $customerId,
                        ShipHeroInventoryService::CATALOG_SYNC_INCREMENTAL
                    );
                    $inventoryQueued++;
                    $this->line('  Queued catalog sync for account #'.$account->id);
                } catch (Throwable $e) {
                    $failures[] = 'inventory #'.$account->id.': '.$e->getMessage();
                    $this->warn('  Account #'.$account->id.' failed: '.$e->getMessage());
                }
            }
        }

        $orderRows = Schema::hasTable('shiphero_order_queue_index')
            ? (int) DB::table('shiphero_order_queue_index')->count()
            : null;

        $this->line('');
        $this->printSummary($accounts->count(), $orderRows, $dashboard, $inventoryQueued);

        if ($failures !== []) {
            $this->line('');
            $this->error('Completed with '.count($failures).' failure(s).');

            return self::FAILURE;
        }

        $this->info('Warm-up complete.');

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, ClientAccount>|null
     */
    private function resolveAccounts(string $accountOpt)
    {
        if ($accountOpt !== '') {
            $accountId = (int) $accountOpt;
            if ($accountId <= 0) {
                $this->error('Invalid account id.');

                return null;
            }

            $account = ClientAccount::query()->find($accountId);
            if ($account === null) {
                $this->error('Client account #'.$accountId.' not found.');

                return null;
            }

            $customerId = trim((string) ($account->shiphero_customer_account_id ?? ''));
            if ($customerId === '') {
                $this->error('Client account #'.$accountId.' has no shiphero_customer_account_id.');

                return null;
            }

            return collect([$account]);
        }

        return ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('id')
            ->get();
    }

    private function printSummary(int $accountsSynced, ?int $orderRows, ?OrderDashboardSnapshotService $dashboard, int $inventoryQueued): void
    {
        $this->comment('Summary');
        $this->line('Accounts synced: '.$accountsSynced);

        if ($orderRows !== null) {
            $this->line('shiphero_order_queue_index rows: '.$orderRows);
        }

        if ($dashboard !== null && Schema::hasTable('order_dashboard_sections')) {
            $totals = DB::table('order_dashboard_sections')
                ->whereIn('section_key', ['ready_to_ship', 'shipped', 'hold_operator', 'asn_pending'])
                ->pluck('total_count', 'section_key');
            $this->line('Dashboard totals (sample): ready_to_ship='.(int) ($totals['ready_to_ship'] ?? 0)
                .' shipped='.(int) ($totals['shipped'] ?? 0)
                .' asn_pending='.(int) ($totals['asn_pending'] ?? 0));
        }

        if ($inventoryQueued > 0) {
            $this->line('Inventory catalog jobs queued: '.$inventoryQueued.' (requires queue worker)');
        }
    }
}
