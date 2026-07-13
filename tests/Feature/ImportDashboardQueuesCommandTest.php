<?php

namespace Tests\Feature;

use App\Console\Commands\ImportDashboardQueuesCommand;
use App\Models\ClientAccount;
use App\Services\OrderDashboardSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ImportDashboardQueuesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_imports_configured_tabs_for_all_linked_accounts(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Import Co',
            'email' => 'import@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => '99901',
        ]);

        $snapshots = Mockery::mock(OrderDashboardSnapshotService::class);
        $snapshots->shouldReceive('importDashboardAccount')
            ->once()
            ->with((int) $account->id, 'awaiting')
            ->andReturn(['truncated' => false]);
        $snapshots->shouldReceive('importDashboardAccount')
            ->once()
            ->with((int) $account->id, 'shipped')
            ->andReturn(['truncated' => false]);
        $snapshots->shouldReceive('getDashboardPayload')
            ->once()
            ->andReturn(['totals' => ['ready_to_ship' => 1, 'on_hold' => 2, 'shipped' => 3]]);

        $this->app->instance(OrderDashboardSnapshotService::class, $snapshots);

        $this->artisan('orders:import-dashboard-queues')
            ->assertExitCode(0)
            ->expectsOutputToContain('Import Co');

        $this->assertNotNull(Cache::get(ImportDashboardQueuesCommand::LAST_RUN_CACHE_KEY));
    }
}
