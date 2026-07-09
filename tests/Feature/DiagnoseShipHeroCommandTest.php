<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\OrderDashboardSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DiagnoseShipHeroCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnose_exits_zero_and_prints_key_sections(): void
    {
        Config::set('services.shiphero.refresh_token', 'test-refresh-token');

        ClientAccount::query()->create([
            'company_name' => 'Linked Co',
            'email' => 'linked@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => '12345',
        ]);

        ClientAccount::query()->create([
            'company_name' => 'Unlinked Co',
            'email' => 'unlinked@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        OrderDashboardSection::query()->create([
            'section_key' => OrderDashboardSection::KEY_READY_TO_SHIP,
            'status' => OrderDashboardSection::STATUS_IDLE,
            'total_count' => 4,
            'refreshed_at' => now(),
        ]);

        $this->artisan('crm:diagnose-shiphero')
            ->assertExitCode(0)
            ->expectsOutputToContain('ShipHero diagnostics')
            ->expectsOutputToContain('SHIPHERO_REFRESH_TOKEN set: yes')
            ->expectsOutputToContain('With shiphero_customer_account_id: 1')
            ->expectsOutputToContain('Without shiphero_customer_account_id: 1')
            ->expectsOutputToContain('Pending jobs:')
            ->expectsOutputToContain('shiphero_order_queue_index rows (total):')
            ->expectsOutputToContain('shiphero_order_queue_index by queue_kind:')
            ->expectsOutputToContain('awaiting:')
            ->expectsOutputToContain('ready_to_ship:');
    }

    public function test_diagnose_warns_when_only_on_hold_index_rows_exist(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('shiphero_order_queue_index')) {
            $this->markTestSkipped('shiphero_order_queue_index table not migrated.');
        }

        $account = ClientAccount::query()->create([
            'company_name' => 'Hold Only Co',
            'email' => 'holdonly@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => '55501',
        ]);

        \Illuminate\Support\Facades\DB::table('shiphero_order_queue_index')->insert([
            'client_account_id' => $account->id,
            'queue_kind' => 'on_hold',
            'shiphero_order_id' => 'ord-test-1',
            'order_number_search' => '1001',
            'order_date' => now(),
            'list_payload' => json_encode(['id' => 'ord-test-1']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('crm:diagnose-shiphero')
            ->assertExitCode(0)
            ->expectsOutputToContain('on_hold: 1')
            ->expectsOutputToContain('Only on_hold has rows');
    }
}
