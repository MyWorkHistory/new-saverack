<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarmShipHeroDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_linked_accounts_without_syncing(): void
    {
        $linked = ClientAccount::query()->create([
            'company_name' => 'Warm Test Co',
            'email' => 'warm@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => '99887',
        ]);

        ClientAccount::query()->create([
            'company_name' => 'No ShipHero',
            'email' => 'noid@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        $this->artisan('crm:warm-shiphero-data', ['--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Warming ShipHero data for 1 account(s) (dry run)')
            ->expectsOutputToContain('Would sync order queue index for account ids: '.$linked->id)
            ->expectsOutputToContain('Would refresh home dashboard sections: all')
            ->expectsOutputToContain('Would dispatch inventory catalog sync for 1 account(s)');
    }

    public function test_dry_run_with_skip_inventory_omits_catalog_step(): void
    {
        ClientAccount::query()->create([
            'company_name' => 'Warm Skip Inv',
            'email' => 'skip@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => '55443',
        ]);

        $this->artisan('crm:warm-shiphero-data', ['--dry-run' => true, '--skip-inventory' => true])
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('Would dispatch inventory catalog sync');
    }

    public function test_invalid_account_id_returns_failure(): void
    {
        $this->artisan('crm:warm-shiphero-data', ['--account' => '0', '--dry-run' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid account id.');
    }
}
