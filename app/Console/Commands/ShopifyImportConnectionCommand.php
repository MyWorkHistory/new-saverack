<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Services\ShopifyConnectionService;
use Illuminate\Console\Command;
use Throwable;

class ShopifyImportConnectionCommand extends Command
{
    protected $signature = 'shopify:import-connection
        {account : Client account id}';

    protected $description = 'Run full Shopify bootstrap import for a connected client account';

    public function handle(ShopifyConnectionService $connections): int
    {
        $account = ClientAccount::query()->find((int) $this->argument('account'));
        if ($account === null) {
            $this->error('Client account not found.');

            return self::FAILURE;
        }

        $connection = $connections->getForAccount((int) $account->id);
        if ($connection === null || ! $connection->hasCredentials()) {
            $this->error('No Shopify credentials for this account.');

            return self::FAILURE;
        }

        try {
            $connections->syncNowInline($connection);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Import completed for account #'.$account->id);

        return self::SUCCESS;
    }
}
