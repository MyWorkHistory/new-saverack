<?php

namespace App\Console\Commands;

use App\Jobs\FinalizeInventoryCatalogSyncJob;
use App\Jobs\SyncInventoryCatalogPageJob;
use App\Models\ClientAccount;
use App\Models\ShipHeroInventoryProductIndex;
use App\Services\ShipHeroInventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DiagnoseInventoryCatalogSyncCommand extends Command
{
    protected $signature = 'inventory:diagnose-catalog-sync
        {client_account_id? : Client account id (omit to list running syncs and queue totals)}';

    protected $description = 'Diagnose inventory catalog sync status, progress, errors, and pending queue jobs';

    public function handle(ShipHeroInventoryService $inventory): int
    {
        $accountId = $this->argument('client_account_id');

        if ($accountId === null || trim((string) $accountId) === '') {
            $this->printQueueSummary();

            return $this->printRunningAccounts();
        }

        $id = (int) $accountId;
        $account = ClientAccount::query()->find($id);
        if ($account === null) {
            $this->error("Client account {$id} not found.");

            return self::FAILURE;
        }

        $inventory->resolveStaleRunningCatalogSync($id);
        $account->refresh();

        $this->info("Catalog sync diagnosis for account {$id} ({$account->company_name})");
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['status', (string) ($account->inventory_catalog_sync_status ?? 'idle')],
                ['started_at', optional($account->inventory_catalog_sync_started_at)->toDateTimeString() ?? '—'],
                ['last_progress_at', optional($account->inventory_catalog_sync_last_progress_at)->toDateTimeString() ?? '—'],
                ['pages_completed', (string) (int) ($account->inventory_catalog_sync_pages_completed ?? 0)],
                ['synced_at', optional($account->inventory_catalog_synced_at)->toDateTimeString() ?? '—'],
                ['product_count (meta)', (string) (int) ($account->inventory_catalog_product_count ?? 0)],
                ['last_error', $account->inventory_catalog_sync_last_error ?? '—'],
            ]
        );

        $indexRows = (int) ShipHeroInventoryProductIndex::query()
            ->where('client_account_id', $id)
            ->where('product_active', true)
            ->count();

        $this->line("Active index rows: {$indexRows}");
        $this->newLine();

        $this->printQueueSummary($id);
        $this->newLine();

        $stallMinutes = $inventory->catalogSyncStallMinutes();
        $maxHours = $inventory->catalogSyncMaxRuntimeHours();
        $this->line("Stall threshold: {$stallMinutes} minutes without progress");
        $this->line("Max runtime: {$maxHours} hours");
        $this->newLine();
        $this->line('Queue worker required: php artisan queue:work database-long --timeout=3700 --tries=1');
        $this->line('If stuck running: php artisan inventory:reset-catalog-sync '.$id);
        $this->line('Watch logs for: inventory.catalog_sync.page, inventory.catalog_sync.completed, inventory.catalog_sync.failed');

        return self::SUCCESS;
    }

    private function printRunningAccounts(): int
    {
        try {
            $accounts = ClientAccount::query()
                ->whereIn('inventory_catalog_sync_status', ['running', 'failed'])
                ->orderBy('id')
                ->get([
                    'id',
                    'company_name',
                    'inventory_catalog_sync_status',
                    'inventory_catalog_sync_pages_completed',
                    'inventory_catalog_sync_last_progress_at',
                    'inventory_catalog_sync_last_error',
                ]);
        } catch (\Throwable $e) {
            $this->error('Could not query client_accounts: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($accounts->isEmpty()) {
            $this->info('No accounts with catalog sync status running or failed.');

            return self::SUCCESS;
        }

        $this->info('Accounts with running or failed catalog sync:');
        $this->table(
            ['id', 'company', 'status', 'pages', 'last_progress', 'last_error'],
            $accounts->map(static function (ClientAccount $account) {
                return [
                    $account->id,
                    $account->company_name,
                    $account->inventory_catalog_sync_status,
                    (int) ($account->inventory_catalog_sync_pages_completed ?? 0),
                    optional($account->inventory_catalog_sync_last_progress_at)->toDateTimeString() ?? '—',
                    $account->inventory_catalog_sync_last_error !== null
                        ? mb_substr((string) $account->inventory_catalog_sync_last_error, 0, 60)
                        : '—',
                ];
            })->all()
        );
        $this->line('Run: php artisan inventory:diagnose-catalog-sync {id}');

        return self::SUCCESS;
    }

    private function printQueueSummary(?int $clientAccountId = null): bool
    {
        try {
            if (! Schema::hasTable('jobs')) {
                $this->warn('jobs table not found (queue driver may not use database).');

                return true;
            }
        } catch (\Throwable $e) {
            $this->warn('Could not read jobs table: '.$e->getMessage());

            return true;
        }

        $queue = 'database-long';
        $pageJob = SyncInventoryCatalogPageJob::class;
        $finalizeJob = FinalizeInventoryCatalogSyncJob::class;

        $total = (int) DB::table('jobs')->where('queue', $queue)->count();
        $pageTotal = $this->countJobsForClass($queue, $pageJob);
        $finalizeTotal = $this->countJobsForClass($queue, $finalizeJob);

        $this->info("Pending jobs on queue \"{$queue}\": {$total}");
        $this->line("  SyncInventoryCatalogPageJob: {$pageTotal}");
        $this->line("  FinalizeInventoryCatalogSyncJob: {$finalizeTotal}");

        if ($clientAccountId !== null && $clientAccountId > 0) {
            $needle = '"clientAccountId";i:'.$clientAccountId.';';
            $pageForAccount = (int) DB::table('jobs')
                ->where('queue', $queue)
                ->where('payload', 'like', '%'.$pageJob.'%')
                ->where('payload', 'like', '%'.$needle.'%')
                ->count();
            $finalizeForAccount = (int) DB::table('jobs')
                ->where('queue', $queue)
                ->where('payload', 'like', '%'.$finalizeJob.'%')
                ->where('payload', 'like', '%'.$needle.'%')
                ->count();

            $this->line("  For account {$clientAccountId}: {$pageForAccount} page job(s), {$finalizeForAccount} finalize job(s)");
        }

        if ($total > 0 && $pageTotal === 0 && $finalizeTotal === 0) {
            $this->warn('Jobs exist on database-long but none match catalog sync job classes.');
        }

        if ($pageTotal > 0 || $finalizeTotal > 0) {
            $this->comment('Jobs will not run until queue:work database-long is active.');
        }

        return true;
    }

    private function countJobsForClass(string $queue, string $class): int
    {
        return (int) DB::table('jobs')
            ->where('queue', $queue)
            ->where('payload', 'like', '%'.$class.'%')
            ->count();
    }
}
