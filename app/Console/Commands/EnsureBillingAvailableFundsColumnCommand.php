<?php

namespace App\Console\Commands;

use App\Support\BillingAvailableFundsSchema;
use Illuminate\Console\Command;

class EnsureBillingAvailableFundsColumnCommand extends Command
{
    protected $signature = 'billing:ensure-available-funds-column';

    protected $description = 'Add client_accounts.billing_available_funds_cents if missing (repair stuck migrations)';

    public function handle(): int
    {
        if (BillingAvailableFundsSchema::columnExists()) {
            $this->info('Column billing_available_funds_cents already exists.');

            return self::SUCCESS;
        }

        BillingAvailableFundsSchema::ensureColumn();

        if (BillingAvailableFundsSchema::columnExists()) {
            $this->info('Added billing_available_funds_cents to client_accounts.');

            return self::SUCCESS;
        }

        $this->error('Column could not be created. Check database permissions.');

        return self::FAILURE;
    }
}
