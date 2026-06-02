<?php

namespace App\Console\Commands;

use App\Services\ShipHeroCustomerAccountService;
use Illuminate\Console\Command;

class ProbeShipHeroCustomerMutationsCommand extends Command
{
    protected $signature = 'shiphero:probe-customer-mutations';

    protected $description = 'Probe ShipHero GraphQL for customer account create/update and hide-orders fields';

    /** @var ShipHeroCustomerAccountService */
    protected $service;

    public function __construct(ShipHeroCustomerAccountService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        $createCandidates = $this->service->findCustomerAccountCreateMutations();
        if ($createCandidates !== []) {
            $this->info('Candidate create mutations:');
            foreach ($createCandidates as $name) {
                $this->line('  - '.$name);
            }
        } else {
            $this->warn('No customer/account create mutation names found (or schema probe failed).');
        }

        $this->line('');
        $probe = $this->service->probeHideOrdersCapability();

        $updateMutations = $probe['update_mutations'];
        if ($updateMutations !== []) {
            $this->info('Candidate update mutations:');
            foreach ($updateMutations as $name) {
                $this->line('  - '.$name);
            }
        } else {
            $this->warn('No customer/account update mutation names found.');
        }

        $hideCandidates = $probe['hide_field_candidates'];
        if ($hideCandidates !== []) {
            $this->line('');
            $this->info('Hide-orders field candidates (CustomerAccount / update inputs):');
            foreach ($hideCandidates as $name) {
                $this->line('  - '.$name);
            }
        }

        $discovered = $probe['discovered_config'];
        $this->line('');
        if (is_array($discovered) && $discovered !== []) {
            $this->info('Auto-discovered hide-orders sync config:');
            $this->line('  SHIPHERO_CUSTOMER_ACCOUNT_UPDATE_MUTATION='.$discovered['mutation']);
            $this->line('  SHIPHERO_CUSTOMER_ACCOUNT_UPDATE_INPUT_TYPE='.$discovered['input_type']);
            $this->line('  SHIPHERO_CUSTOMER_ACCOUNT_HIDE_ORDERS_FIELD='.$discovered['hide_field']);
            $this->line('  SHIPHERO_CUSTOMER_ACCOUNT_ID_FIELD='.$discovered['id_field']);
        } else {
            $this->warn('Could not auto-discover hide-orders sync. Set env vars manually after checking ShipHero schema.');
            $this->line('See docs/shiphero-customer-provisioning.md');
        }

        if ($createCandidates === [] && $updateMutations === []) {
            $this->line('');
            $this->line('Staff must set shiphero_customer_account_id manually in CRM.');
        }

        return 0;
    }
}
