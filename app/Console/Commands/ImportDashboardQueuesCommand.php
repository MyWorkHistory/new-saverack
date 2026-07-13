<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\ShipHeroOrderQueueIndex;
use App\Services\OrderDashboardSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ImportDashboardQueuesCommand extends Command
{
    public const LAST_RUN_CACHE_KEY = 'shiphero:schedule:last_run:orders_import_dashboard_queues';

    protected $signature = 'orders:import-dashboard-queues
        {--tabs=awaiting,shipped : Comma-separated queue tabs or "all"}
        {--account= : Optional client account id}';

    protected $description = 'Import ShipHero queue tabs for linked accounts (index rebuild + admin dashboard + portal cache)';

    public function handle(OrderDashboardSnapshotService $snapshots): int
    {
        $tabs = $this->parseTabs((string) $this->option('tabs'));
        if ($tabs === null) {
            $this->error('Invalid tabs. Use: '.implode(', ', ShipHeroOrderQueueIndex::QUEUE_KINDS).' or all');

            return self::FAILURE;
        }

        $query = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('company_name');

        $accountOpt = trim((string) $this->option('account'));
        if ($accountOpt !== '') {
            $query->where('id', (int) $accountOpt);
        }

        $accounts = $query->get(['id', 'company_name', 'shiphero_customer_account_id']);
        if ($accounts->isEmpty()) {
            $this->warn('No linked client accounts found.');

            return self::SUCCESS;
        }

        $tabLabel = implode(',', $tabs);
        $this->info('Importing '.$accounts->count().' account(s), tabs='.$tabLabel.'…');
        $this->line('');

        $failures = 0;
        $truncated = 0;

        foreach ($accounts as $account) {
            $accountId = (int) $account->id;
            $this->line('Account #'.$accountId.' '.$account->company_name.'…');

            foreach ($tabs as $tab) {
                try {
                    $syncResult = $snapshots->importDashboardAccount($accountId, $tab);
                    if (! empty($syncResult['truncated'])) {
                        $truncated++;
                    }
                } catch (Throwable $e) {
                    $failures++;
                    $this->warn('  Tab '.$tab.' failed: '.$e->getMessage());
                }
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

        Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addDays(7));

        if ($failures > 0) {
            $this->error('Completed with '.$failures.' failure(s).');

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function parseTabs(string $raw): ?array
    {
        $raw = strtolower(trim($raw));
        if ($raw === '' || $raw === 'all') {
            return ShipHeroOrderQueueIndex::QUEUE_KINDS;
        }

        $tabs = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $part) {
            if (! in_array($part, ShipHeroOrderQueueIndex::QUEUE_KINDS, true)) {
                return null;
            }
            $tabs[] = $part;
        }

        return $tabs === [] ? null : array_values(array_unique($tabs));
    }
}
