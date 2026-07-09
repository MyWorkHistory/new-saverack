<?php

namespace Tests\Feature;

use App\Jobs\SyncInventoryCatalogPageJob;
use App\Models\ClientAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class SyncInventoryCatalogIncrementalCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_incremental_command_queues_catalog_sync_for_linked_accounts(): void
    {
        Bus::fake();

        $account = ClientAccount::create([
            'company_name' => 'Incremental Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-inc-1',
            'inventory_catalog_sync_status' => 'idle',
        ]);

        $this->artisan('inventory:sync-catalog-incremental')
            ->assertSuccessful();

        Bus::assertDispatched(SyncInventoryCatalogPageJob::class, function ($job) use ($account) {
            return (int) $job->clientAccountId === (int) $account->id;
        });
    }

    public function test_incremental_command_skips_accounts_with_running_catalog_sync(): void
    {
        Bus::fake();

        ClientAccount::create([
            'company_name' => 'Running Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-inc-2',
            'inventory_catalog_sync_status' => 'running',
            'inventory_catalog_sync_started_at' => now(),
        ]);

        $this->artisan('inventory:sync-catalog-incremental')
            ->assertSuccessful();

        Bus::assertNotDispatched(SyncInventoryCatalogPageJob::class);
    }

    public function test_incremental_command_resets_stale_running_sync_over_75_minutes(): void
    {
        Bus::fake();

        $account = ClientAccount::create([
            'company_name' => 'Stale Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-inc-3',
            'inventory_catalog_sync_status' => 'running',
            'inventory_catalog_sync_started_at' => now()->subMinutes(80),
        ]);

        $this->artisan('inventory:sync-catalog-incremental')
            ->assertSuccessful();

        $account->refresh();
        $this->assertSame('failed', (string) $account->inventory_catalog_sync_status);

        Bus::assertDispatched(SyncInventoryCatalogPageJob::class);
    }
}
