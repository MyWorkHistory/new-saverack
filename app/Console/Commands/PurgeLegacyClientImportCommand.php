<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes rows created by crm:import-legacy-clients (those with legacy_* IDs set).
 */
class PurgeLegacyClientImportCommand extends Command
{
    protected $signature = 'crm:purge-legacy-import
                            {--dry-run : List counts only, do not delete}
                            {--force : Delete without confirmation (for scripts)}';

    protected $description = 'Delete client_stores and client_accounts rows that were created by the legacy import (legacy_store_id / legacy_customer_id)';

    public function handle(): int
    {
        if (! Schema::hasColumn('client_accounts', 'legacy_customer_id')
            || ! Schema::hasColumn('client_stores', 'legacy_store_id')) {
            $this->error('Migration adding legacy_customer_id / legacy_store_id has not been run.');

            return self::FAILURE;
        }

        $stores = (int) DB::table('client_stores')->whereNotNull('legacy_store_id')->count();
        $accounts = (int) DB::table('client_accounts')->whereNotNull('legacy_customer_id')->count();

        $this->info("Rows with legacy import keys: client_stores={$stores}, client_accounts={$accounts}");

        if ($stores === 0 && $accounts === 0) {
            $this->info('Nothing to purge.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run: no rows deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$stores} stores and {$accounts} accounts tied to legacy import?", false)) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($stores, $accounts) {
            DB::table('client_stores')->whereNotNull('legacy_store_id')->delete();
            DB::table('client_accounts')->whereNotNull('legacy_customer_id')->delete();
        });

        $this->info("Deleted client_stores: {$stores}, client_accounts: {$accounts}");

        return self::SUCCESS;
    }
}
