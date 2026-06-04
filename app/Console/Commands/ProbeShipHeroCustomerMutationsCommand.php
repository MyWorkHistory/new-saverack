<?php

namespace App\Console\Commands;

use App\Services\ShipHeroCustomerAccountService;
use Illuminate\Console\Command;

class ProbeShipHeroCustomerMutationsCommand extends Command
{
    protected $signature = 'shiphero:probe-customer-mutations
                            {--test-customer-id= : ShipHero customer_account_id to trial hide-orders mutations}
                            {--warehouse-id= : Optional warehouse id for customer settings queries}';

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
        $this->info('ShipHero API diagnostics');
        $this->line('');

        $diag = $this->service->diagnoseApiAccess();

        $this->line('  SHIPHERO_REFRESH_TOKEN: '.($diag['refresh_token_configured'] ? 'set' : 'MISSING'));
        if ($diag['token_refresh']['ok']) {
            $this->line('  Token refresh: OK');
        } else {
            $this->error('  Token refresh failed: '.($diag['token_refresh']['message'] ?? 'unknown'));
            $this->line('');
            $this->warn('Fix SHIPHERO_REFRESH_TOKEN before hide-orders sync can work.');

            return 1;
        }

        if ($diag['account_query']['ok']) {
            $this->line('  Account query: OK (request_id '.($diag['account_query']['request_id'] ?? '—').')');
        } else {
            $this->error('  Account query failed: '.($diag['account_query']['message'] ?? 'unknown'));

            return 1;
        }

        if ($diag['introspection']['ok']) {
            $this->line('  Schema introspection: OK ('.$diag['introspection']['mutation_count'].' mutations)');
        } else {
            $this->warn('  Schema introspection: unavailable');
            $this->line('    '.($diag['introspection']['message'] ?? 'Introspection disabled or blocked.'));
            $this->line('    Falling back to direct mutation probing…');
        }

        if ($diag['warehouses'] !== []) {
            $this->line('  Warehouses:');
            foreach ($diag['warehouses'] as $wh) {
                $label = $wh['identifier'] !== null && $wh['identifier'] !== '' ? $wh['identifier'] : 'warehouse';
                $this->line('    - '.$label.' ('.$wh['id'].')');
            }
        }

        $this->line('');
        $createCandidates = $this->service->findCustomerAccountCreateMutations();
        if ($createCandidates !== []) {
            $this->info('Candidate create mutations (introspection):');
            foreach ($createCandidates as $name) {
                $this->line('  - '.$name);
            }
        } else {
            $this->warn('No customer/account create mutations found via introspection.');
        }

        $this->line('');
        $this->info('Direct mutation probe (no introspection):');
        $existingMutations = [];
        foreach ($this->service->hideOrdersMutationCandidates() as $mutationName) {
            $signature = $this->service->probeMutationSignature($mutationName);
            if (! $signature['exists']) {
                continue;
            }
            $existingMutations[] = $mutationName;
            $detail = $mutationName;
            if ($signature['input_type'] !== null) {
                $detail .= ' ('.($signature['arg_name'] ?? 'data').': '.$signature['input_type'].')';
            }
            $this->line('  - '.$detail);
        }
        if ($existingMutations === []) {
            $this->warn('  No candidate customer update mutations responded on this token.');
        }

        $this->line('');
        $probe = $this->service->probeHideOrdersCapability();

        $updateMutations = $probe['update_mutations'];
        if ($updateMutations !== []) {
            $this->info('Candidate update mutations (introspection):');
            foreach ($updateMutations as $name) {
                $this->line('  - '.$name);
            }
        }

        $hideCandidates = $probe['hide_field_candidates'];
        if ($hideCandidates !== []) {
            $this->line('');
            $this->info('Hide-orders field candidates (introspection):');
            foreach ($hideCandidates as $name) {
                $this->line('  - '.$name);
            }
        }

        $testCustomerId = trim((string) $this->option('test-customer-id'));
        $discovered = $probe['discovered_config'];
        if (! is_array($discovered) || $discovered === []) {
            $discovered = $this->service->bruteForceDiscoverHideOrdersSyncConfig(
                $testCustomerId !== '' ? $testCustomerId : null
            );
        }

        $this->line('');
        if (is_array($discovered) && $discovered !== []) {
            $this->info('Auto-discovered hide-orders sync config:');
            $this->line('  SHIPHERO_CUSTOMER_ACCOUNT_UPDATE_MUTATION='.$discovered['mutation']);
            $this->line('  SHIPHERO_CUSTOMER_ACCOUNT_UPDATE_INPUT_TYPE='.$discovered['input_type']);
            $this->line('  SHIPHERO_CUSTOMER_ACCOUNT_HIDE_ORDERS_FIELD='.$discovered['hide_field']);
            $this->line('  SHIPHERO_CUSTOMER_ACCOUNT_ID_FIELD='.$discovered['id_field']);
            $this->line('');
            $this->line('Then run: php artisan config:clear');
        } else {
            $this->warn('Could not auto-discover hide-orders sync.');
            $this->line('');
            $this->line('ShipHero\'s Public API may not expose "Hide Customer\'s Orders From App".');
            $this->line('That checkbox is managed per warehouse in ShipHero → 3PL Customers:');
            $this->line('https://software-help.shiphero.com/hc/en-us/articles/4419345401485');
            $this->line('');
            $this->line('CRM status changes and in-house Slack notifications still work.');
            $this->line('For API sync, ask ShipHero support whether a mutation exists for your account,');
            $this->line('or retry with a real customer id:');
            $this->line('  php artisan shiphero:probe-customer-mutations --test-customer-id=YOUR_SHIPHERO_CUSTOMER_ID');
            $this->line('');
            $this->line('See docs/shiphero-customer-provisioning.md');
        }

        if ($createCandidates === [] && $updateMutations === [] && $existingMutations === []) {
            $this->line('');
            $this->line('Staff must set shiphero_customer_account_id manually in CRM when provisioning accounts.');
        }

        return is_array($discovered) && $discovered !== [] ? 0 : 1;
    }
}
