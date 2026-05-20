<?php

namespace App\Console\Commands;

use App\Services\ShipHeroCustomerAccountService;
use Illuminate\Console\Command;

class ProbeShipHeroCustomerMutationsCommand extends Command
{
    protected $signature = 'shiphero:probe-customer-mutations';

    protected $description = 'List ShipHero GraphQL mutations related to customer/account creation';

    /** @var ShipHeroCustomerAccountService */
    protected $service;

    public function __construct(ShipHeroCustomerAccountService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        $candidates = $this->service->findCustomerAccountCreateMutations();
        if ($candidates === []) {
            $this->warn('No customer/account-related mutation names found (or schema probe failed).');
            $this->line('See docs/shiphero-customer-provisioning.md — staff must set shiphero_customer_account_id manually.');

            return 0;
        }

        $this->info('Candidate mutations:');
        foreach ($candidates as $name) {
            $this->line('  - '.$name);
        }

        return 0;
    }
}
