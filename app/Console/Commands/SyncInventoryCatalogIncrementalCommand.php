<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Services\ShipHeroInventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncInventoryCatalogIncrementalCommand extends Command
{
    protected $signature = 'inventory:sync-catalog-incremental
        {--account= : Optional client account id}';

    protected $description = 'Queue incremental inventory catalog sync for each linked ShipHero account';

    public function handle(ShipHeroInventoryService $inventory): int
    {
        $query = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('id');

        $accountOpt = trim((string) $this->option('account'));
        if ($accountOpt !== '') {
            $query->where('id', (int) $accountOpt);
        }

        $accounts = $query->get(['id', 'shiphero_customer_account_id', 'company_name', 'inventory_catalog_sync_status', 'inventory_catalog_sync_started_at']);
        if ($accounts->isEmpty()) {
            $this->warn('No linked client accounts.');

            return self::SUCCESS;
        }

        $queued = 0;
        $skipped = 0;

        foreach ($accounts as $account) {
            $clientAccountId = (int) $account->id;
            $customerId = trim((string) $account->shiphero_customer_account_id);
            if ($customerId === '') {
                continue;
            }

            $this->resetStaleRunningCatalogSync($account, $inventory);

            if ($inventory->isCatalogSyncInProgress($clientAccountId)) {
                $this->line('Skipping account #'.$clientAccountId.' (catalog sync already running).');
                $skipped++;
                continue;
            }

            try {
                $inventory->dispatchCatalogSyncJob(
                    $clientAccountId,
                    $customerId,
                    ShipHeroInventoryService::CATALOG_SYNC_INCREMENTAL
                );
                $queued++;
                $this->line('Queued incremental catalog sync for account #'.$clientAccountId.' ('.$account->company_name.').');
            } catch (Throwable $e) {
                $this->warn('Account #'.$clientAccountId.': '.$e->getMessage());
                Log::warning('inventory.sync_catalog_incremental.failed', [
                    'client_account_id' => $clientAccountId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->info('Queued '.$queued.' account(s), skipped '.$skipped.'.');
        Cache::put('shiphero:schedule:last_run:inventory_sync_catalog_incremental', now()->toIso8601String(), now()->addDays(7));

        return self::SUCCESS;
    }

    private function resetStaleRunningCatalogSync(ClientAccount $account, ShipHeroInventoryService $inventory): void
    {
        if ((string) ($account->inventory_catalog_sync_status ?? 'idle') !== 'running') {
            return;
        }

        $startedAt = $account->inventory_catalog_sync_started_at;
        if ($startedAt !== null && $startedAt->diffInMinutes(now()) > 75) {
            $inventory->markCatalogSyncFailed((int) $account->id);
            $this->warn('Reset stale catalog sync for account #'.$account->id.' (>75m).');
        }
    }
}
