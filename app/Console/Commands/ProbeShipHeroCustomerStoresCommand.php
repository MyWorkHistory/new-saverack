<?php

namespace App\Console\Commands;

use App\Services\ShipHeroStoreService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ProbeShipHeroCustomerStoresCommand extends Command
{
    protected $signature = 'shiphero:probe-customer-stores
                            {customer_account_id : ShipHero customer_account_id (3PL child account)}';

    protected $description = 'Probe ShipHero GraphQL user(customer_account_id).stores for a customer account';

    /** @var ShipHeroStoreService */
    protected $stores;

    public function __construct(ShipHeroStoreService $stores)
    {
        parent::__construct();
        $this->stores = $stores;
    }

    public function handle(): int
    {
        $customerId = trim((string) $this->argument('customer_account_id'));
        if ($customerId === '') {
            $this->error('customer_account_id is required.');

            return 1;
        }

        $this->info('Probing ShipHero stores for customer_account_id: '.$customerId);
        $this->line('');

        try {
            $result = $this->stores->fetchFromShipHeroCustomerId($customerId);
        } catch (RuntimeException $e) {
            $this->error('Probe failed: '.$e->getMessage());

            return 1;
        } catch (Throwable $e) {
            $this->error('Probe failed: '.$e->getMessage());

            return 1;
        }

        $stores = $result['stores'] ?? [];
        if ($stores !== []) {
            $this->line('  method: users(customer_account_id)');
        }
        $count = count($stores);
        $this->info('Stores returned: '.$count);
        if (isset($result['request_id'])) {
            $this->line('  request_id: '.$result['request_id']);
        }

        if ($count === 0) {
            $this->warn('No stores returned. Confirm the customer has connected stores in ShipHero UI.');

            return 1;
        }

        $this->line('');
        $this->table(
            ['legacy_id', 'shop_name', 'settings_url'],
            array_map(function (array $row) {
                return [
                    $row['legacy_id'] ?? '—',
                    $row['shop_name'] ?? '—',
                    $row['settings_url'] ?? '—',
                ];
            }, array_slice($stores, 0, 10))
        );

        if ($count > 10) {
            $this->line('  … and '.($count - 10).' more');
        }

        return 0;
    }
}
