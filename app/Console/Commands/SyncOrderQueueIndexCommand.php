<?php

namespace App\Console\Commands;

use App\Jobs\RefreshOrderQueueIndexJob;
use App\Models\ClientAccount;
use App\Models\ShipHeroOrderQueueIndex;
use App\Services\ShipHeroOrderQueueIndexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncOrderQueueIndexCommand extends Command
{
    protected $signature = 'orders:sync-queue-index {--account= : Client account id} {--tab=all : Queue tab or all} {--sync : Run inline instead of queueing}';

    protected $description = 'Sync ShipHero order queue tabs into the local order index';

    public function handle(ShipHeroOrderQueueIndexService $index): int
    {
        $accountOpt = trim((string) $this->option('account'));
        $tab = strtolower(trim((string) $this->option('tab')));
        if ($tab === '') {
            $tab = 'all';
        }

        if ($accountOpt !== '') {
            $accountId = (int) $accountOpt;
            if ($accountId <= 0) {
                $this->error('Invalid account id.');

                return self::FAILURE;
            }

            $tabs = $tab === 'all' ? ShipHeroOrderQueueIndex::QUEUE_KINDS : [$tab];
            foreach ($tabs as $queueTab) {
                if (! $index->isQueueTab($queueTab)) {
                    $this->error('Invalid tab: '.$queueTab);

                    return self::FAILURE;
                }
                $this->runSync($index, $accountId, $queueTab);
            }

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $index->syncAllLinkedAccounts($tab === 'all' ? null : $tab);
            Cache::put('shiphero:schedule:last_run:orders_sync_queue_index', now()->toIso8601String(), now()->addDays(7));
            $this->info('Order queue index sync complete.');

            return self::SUCCESS;
        }

        $accounts = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('id')
            ->get(['id']);

        $tabs = $tab === 'all' ? ShipHeroOrderQueueIndex::QUEUE_KINDS : [$tab];
        $delaySeconds = 0;
        foreach ($accounts as $account) {
            foreach ($tabs as $queueTab) {
                if (! $index->isQueueTab($queueTab)) {
                    continue;
                }
                RefreshOrderQueueIndexJob::dispatch((int) $account->id, $queueTab)
                    ->delay(now()->addSeconds($delaySeconds));
                $this->line('Queued '.$queueTab.' for account #'.$account->id.' in '.$delaySeconds.'s');
                $delaySeconds += 3;
            }
        }

        return self::SUCCESS;
    }

    private function runSync(ShipHeroOrderQueueIndexService $index, int $accountId, string $tab): void
    {
        $this->info('Syncing '.$tab.' for account #'.$accountId.'…');
        if ($this->option('sync')) {
            $index->syncAccountQueue($accountId, $tab);
            $this->info('Done.');

            return;
        }

        RefreshOrderQueueIndexJob::dispatch($accountId, $tab);
        $this->info('Queued.');
    }
}
