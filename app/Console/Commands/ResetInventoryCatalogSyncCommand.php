<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use Illuminate\Console\Command;

class ResetInventoryCatalogSyncCommand extends Command
{
    protected $signature = 'inventory:reset-catalog-sync
        {client_account_id? : Client account id (omit to list stuck running accounts)}';

    protected $description = 'Mark stuck inventory catalog sync as failed so a new sync can start';

    public function handle(): int
    {
        $accountId = $this->argument('client_account_id');

        if ($accountId === null || trim((string) $accountId) === '') {
            $stuck = ClientAccount::query()
                ->where('inventory_catalog_sync_status', 'running')
                ->orderBy('id')
                ->get(['id', 'company_name', 'inventory_catalog_sync_started_at']);

            if ($stuck->isEmpty()) {
                $this->info('No accounts with catalog sync status running.');

                return self::SUCCESS;
            }

            $this->table(
                ['id', 'company_name', 'started_at'],
                $stuck->map(static function (ClientAccount $account) {
                    return [
                        $account->id,
                        $account->company_name,
                        optional($account->inventory_catalog_sync_started_at)->toDateTimeString(),
                    ];
                })->all()
            );
            $this->line('Run: php artisan inventory:reset-catalog-sync {id}');

            return self::SUCCESS;
        }

        $id = (int) $accountId;
        $account = ClientAccount::query()->find($id);
        if ($account === null) {
            $this->error("Client account {$id} not found.");

            return self::FAILURE;
        }

        $account->inventory_catalog_sync_status = 'failed';
        $account->save();

        $this->info("Catalog sync for account {$id} ({$account->company_name}) marked failed.");

        return self::SUCCESS;
    }
}
