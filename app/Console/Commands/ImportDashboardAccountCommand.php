<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\ShipHeroOrderQueueIndex;
use App\Services\OrderDashboardSnapshotService;
use Illuminate\Console\Command;

class ImportDashboardAccountCommand extends Command
{
    protected $signature = 'orders:import-dashboard-account
        {account : Client account id}
        {--tab=awaiting : Queue tab: awaiting|on_hold|shipped|backorder|all}';

    protected $description = 'Sync one ShipHero account into the dashboard (index + live count for that account)';

    public function handle(OrderDashboardSnapshotService $snapshots): int
    {
        $accountId = (int) $this->argument('account');
        if ($accountId <= 0) {
            $this->error('Invalid account id.');

            return self::FAILURE;
        }

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

        $tab = strtolower(trim((string) $this->option('tab')));
        if ($tab === '') {
            $tab = 'awaiting';
        }

        if ($tab !== 'all' && ! in_array($tab, ShipHeroOrderQueueIndex::QUEUE_KINDS, true)) {
            $this->error('Invalid tab. Use: '.implode(', ', ShipHeroOrderQueueIndex::QUEUE_KINDS).' or all');

            return self::FAILURE;
        }

        $this->info('Importing account #'.$accountId.' ('.$account->company_name.') tab='.$tab.'…');

        try {
            $snapshots->importDashboardAccount($accountId, $tab);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
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
