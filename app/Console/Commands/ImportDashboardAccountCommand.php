<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\ShipHeroOrderQueueIndex;
use App\Services\OrderDashboardSnapshotService;
use Illuminate\Console\Command;
use Throwable;

class ImportDashboardAccountCommand extends Command
{
    protected $signature = 'orders:import-dashboard-account
        {account : Client account id, or "all" for every linked account}
        {--tab=awaiting : Queue tab: awaiting|on_hold|shipped|backorder|all}';

    protected $description = 'Sync ShipHero account(s) into the dashboard (index + live count per account)';

    public function handle(OrderDashboardSnapshotService $snapshots): int
    {
        $accountArg = strtolower(trim((string) $this->argument('account')));
        $tab = strtolower(trim((string) $this->option('tab')));
        if ($tab === '') {
            $tab = 'awaiting';
        }

        if ($tab !== 'all' && ! in_array($tab, ShipHeroOrderQueueIndex::QUEUE_KINDS, true)) {
            $this->error('Invalid tab. Use: '.implode(', ', ShipHeroOrderQueueIndex::QUEUE_KINDS).' or all');

            return self::FAILURE;
        }

        if ($accountArg === 'all') {
            return $this->importAllAccounts($snapshots, $tab);
        }

        $accountId = (int) $accountArg;
        if ($accountId <= 0) {
            $this->error('Invalid account id. Use a numeric id or "all".');

            return self::FAILURE;
        }

        return $this->importOneAccount($snapshots, $accountId, $tab);
    }

    private function importAllAccounts(OrderDashboardSnapshotService $snapshots, string $tab): int
    {
        $accounts = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'shiphero_customer_account_id']);

        if ($accounts->isEmpty()) {
            $this->warn('No linked client accounts found.');

            return self::SUCCESS;
        }

        $this->info('Importing '.$accounts->count().' account(s), tab='.$tab.'…');
        $this->line('');

        $failures = 0;
        $truncated = 0;

        foreach ($accounts as $account) {
            $accountId = (int) $account->id;
            $this->line('Account #'.$accountId.' '.$account->company_name.'…');

            try {
                $syncResult = $snapshots->importDashboardAccount($accountId, $tab);
                if (! empty($syncResult['truncated']) || ! empty($syncResult['tabs'])) {
                    if (! empty($syncResult['truncated'])) {
                        $truncated++;
                    }
                    if (is_array($syncResult['tabs'] ?? null)) {
                        foreach ($syncResult['tabs'] as $sub) {
                            if (! empty($sub['truncated'])) {
                                $truncated++;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                $failures++;
                $this->warn('  Failed: '.$e->getMessage());
            }
        }

        $this->line('');
        $payload = $snapshots->getDashboardPayload();
        $this->comment('Dashboard totals now:');
        $this->line('  Ready to ship: '.(int) ($payload['totals']['ready_to_ship'] ?? 0));
        $this->line('  On-hold: '.(int) ($payload['totals']['on_hold'] ?? 0));
        $this->line('  Shipped today: '.(int) ($payload['totals']['shipped'] ?? 0));

        if ($truncated > 0) {
            $this->warn($truncated.' account/tab sync(s) were truncated (pagination limit).');
        }

        if ($failures > 0) {
            $this->error('Completed with '.$failures.' failure(s).');

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function importOneAccount(OrderDashboardSnapshotService $snapshots, int $accountId, string $tab): int
    {
        $account = ClientAccount::query()->find($accountId);
        if ($account === null) {
            $this->error('Account #'.$accountId.' not found.');

            return self::FAILURE;
        }

        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            $this->error('Account #'.$accountId.' has no shiphero_customer_account_id.');

            return self::FAILURE;
        }

        $this->info('Importing account #'.$accountId.' ('.$account->company_name.') tab='.$tab.'…');

        try {
            $syncResult = $snapshots->importDashboardAccount($accountId, $tab);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (is_array($syncResult['tabs'] ?? null)) {
            foreach ($syncResult['tabs'] as $subTab => $subResult) {
                $this->line(sprintf(
                    '  %s: pages=%d rows_upserted=%d',
                    $subTab,
                    (int) ($subResult['pages'] ?? 0),
                    (int) ($subResult['rows_upserted'] ?? 0)
                ));
                if (! empty($subResult['truncated'])) {
                    $this->warn('Tab '.$subTab.' was truncated.');
                }
            }
        } else {
            $pages = (int) ($syncResult['pages'] ?? 0);
            $rows = (int) ($syncResult['rows_upserted'] ?? 0);
            $this->line('  pages='.$pages.', rows_upserted='.$rows);
            if ($rows === 0) {
                $this->warn('Imported 0 rows. Run: php artisan orders:probe-awaiting '.$accountId);
            }
            if (! empty($syncResult['truncated'])) {
                $this->warn('Sync was truncated (pagination limit). Some stale rows may remain until webhooks or orders:sync-recent-updates runs.');
            }
        }

        $indexTab = $tab === 'all' ? ShipHeroOrderQueueIndex::KIND_AWAITING : $tab;
        if ($indexTab !== 'all' && in_array($indexTab, ShipHeroOrderQueueIndex::QUEUE_KINDS, true)) {
            $indexCount = ShipHeroOrderQueueIndex::query()
                ->where('client_account_id', $accountId)
                ->where('queue_kind', $indexTab)
                ->count();
            $this->line('Index '.$indexTab.' rows now: '.$indexCount);
        }

        $payload = $snapshots->getDashboardPayload();
        $this->line('Dashboard totals now:');
        $this->line('  Ready to ship: '.(int) ($payload['totals']['ready_to_ship'] ?? 0));
        $this->line('  On-hold: '.(int) ($payload['totals']['on_hold'] ?? 0));
        $this->line('  Shipped today: '.(int) ($payload['totals']['shipped'] ?? 0));
        $this->info('Done.');

        return self::SUCCESS;
    }
}
