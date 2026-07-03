<?php

namespace Tests\Feature;

use App\Jobs\RefreshOrderDashboardSectionJob;
use App\Models\ClientAccount;
use App\Models\InventoryRestockBetaSnapshot;
use App\Models\OrderDashboardSection;
use App\Models\Permission;
use App\Models\PutAwayReceivingSnapshot;
use App\Models\PutAwayReceivingSnapshotRow;
use App\Models\Role;
use App\Models\User;
use App\Services\InventoryRestockBetaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HomeDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $admin = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );
        $user->roles()->attach($admin->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_home_dashboard_returns_sections_and_totals(): void
    {
        $this->actingAsAdmin();

        $now = now();
        OrderDashboardSection::query()->insert([
            'section_key' => OrderDashboardSection::KEY_READY_TO_SHIP,
            'payload' => json_encode([
                'accounts' => [
                    [
                        'account_id' => 12,
                        'account_name' => 'Home Dash Co',
                        'account_status' => 'active',
                        'orders_count' => 3,
                    ],
                ],
                'truncated' => false,
            ]),
            'total_count' => 3,
            'status' => OrderDashboardSection::STATUS_IDLE,
            'refreshed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->getJson('/api/home-dashboard');

        $response->assertOk()
            ->assertJsonPath('totals.ready_to_ship', 3)
            ->assertJsonPath('sections.ready_to_ship.total_count', 3)
            ->assertJsonPath('sections.ready_to_ship.accounts.0.account_name', 'Home Dash Co');
    }

    public function test_home_dashboard_refresh_enqueues_job(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/home-dashboard/refresh', [
            'section' => OrderDashboardSection::KEY_READY_TO_SHIP,
        ]);

        $response->assertOk()
            ->assertJsonPath('refresh_enqueued', true)
            ->assertJsonPath('section', OrderDashboardSection::KEY_READY_TO_SHIP);

        Queue::assertPushed(RefreshOrderDashboardSectionJob::class, function ($job) {
            return $job->sectionKey === OrderDashboardSection::KEY_READY_TO_SHIP;
        });

        $this->assertSame(
            OrderDashboardSection::STATUS_RUNNING,
            OrderDashboardSection::query()
                ->where('section_key', OrderDashboardSection::KEY_READY_TO_SHIP)
                ->value('status')
        );
    }

    public function test_home_dashboard_refresh_asn_runs_sync(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/home-dashboard/refresh', [
            'section' => OrderDashboardSection::KEY_ASN_PENDING,
        ]);

        $response->assertOk()
            ->assertJsonPath('refresh_synced', true)
            ->assertJsonPath('section', OrderDashboardSection::KEY_ASN_PENDING);

        Queue::assertNothingPushed();
    }

    public function test_home_dashboard_includes_widget_payload_for_admin(): void
    {
        $this->actingAsAdmin();

        $paused = ClientAccount::create([
            'company_name' => 'Paused Widget Co',
            'status' => ClientAccount::STATUS_PAUSED,
            'email' => 'paused-widget@test.com',
            'paused_at' => now()->subHours(2),
        ]);

        $accountA = ClientAccount::create([
            'company_name' => 'Put Away A',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'put-away-a@test.com',
        ]);
        $accountB = ClientAccount::create([
            'company_name' => 'Put Away B',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'put-away-b@test.com',
        ]);

        $snapshot = PutAwayReceivingSnapshot::create([
            'warehouse_id' => 'wh-widget',
            'computed_at' => now(),
            'row_count' => 3,
            'status' => PutAwayReceivingSnapshot::STATUS_OK,
        ]);

        PutAwayReceivingSnapshotRow::create([
            'put_away_receiving_snapshot_id' => $snapshot->id,
            'client_account_id' => $accountA->id,
            'sku' => 'SKU-A1',
            'name' => 'Product A1',
            'barcode' => '111',
            'receiving_qty' => 4,
            'pickable_qty' => 0,
            'non_pickable_qty' => 0,
            'on_hand' => 4,
            'backorder' => 0,
        ]);
        PutAwayReceivingSnapshotRow::create([
            'put_away_receiving_snapshot_id' => $snapshot->id,
            'client_account_id' => $accountA->id,
            'sku' => 'SKU-A2',
            'name' => 'Product A2',
            'barcode' => '112',
            'receiving_qty' => 6,
            'pickable_qty' => 0,
            'non_pickable_qty' => 0,
            'on_hand' => 6,
            'backorder' => 0,
        ]);
        PutAwayReceivingSnapshotRow::create([
            'put_away_receiving_snapshot_id' => $snapshot->id,
            'client_account_id' => $accountB->id,
            'sku' => 'SKU-B1',
            'name' => 'Product B1',
            'barcode' => '222',
            'receiving_qty' => 5,
            'pickable_qty' => 0,
            'non_pickable_qty' => 0,
            'on_hand' => 5,
            'backorder' => 0,
        ]);

        InventoryRestockBetaSnapshot::query()->create([
            'original_filename' => 'widget.csv',
            'row_count' => 1,
            'rows' => [
                [
                    'sku' => 'RESTOCK-1',
                    'name' => 'Widget Restock',
                    'on_hand' => 1,
                    'allocated' => 0,
                    'pickable_qty' => 0,
                    'backstock_qty' => 5,
                    'restock_needed' => 2,
                    'client_account_id' => $accountA->id,
                    'account_name' => $accountA->company_name,
                ],
            ],
            'completed_skus' => [],
            'enrichment_status' => InventoryRestockBetaService::ENRICHMENT_COMPLETED,
            'uploaded_at' => now(),
        ]);

        $response = $this->getJson('/api/home-dashboard');

        $response->assertOk()
            ->assertJsonPath('paused_accounts.0.id', $paused->id)
            ->assertJsonPath('paused_accounts.0.company_name', 'Paused Widget Co')
            ->assertJsonPath('put_away_by_account.0.account_id', $accountA->id)
            ->assertJsonPath('put_away_by_account.0.account_name', 'Put Away A')
            ->assertJsonPath('put_away_by_account.0.total_qty', 10)
            ->assertJsonPath('put_away_by_account.1.account_id', $accountB->id)
            ->assertJsonPath('put_away_by_account.1.total_qty', 5)
            ->assertJsonPath('restock_preview.0.sku', 'RESTOCK-1')
            ->assertJsonPath('restock_preview.0.account_name', 'Put Away A')
            ->assertJsonPath('restock_preview.0.restock_needed', 2);
    }

    public function test_home_dashboard_omits_widgets_without_permissions(): void
    {
        $viewDashboard = Permission::query()->firstOrCreate(
            ['key' => 'view-dashboard'],
            ['label' => 'View dashboard', 'module' => 'dashboard']
        );
        $ordersView = Permission::query()->firstOrCreate(
            ['key' => 'orders.view'],
            ['label' => 'View orders', 'module' => 'orders']
        );

        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach([$viewDashboard->id, $ordersView->id]);
        Sanctum::actingAs($user);

        ClientAccount::create([
            'company_name' => 'Hidden Paused Co',
            'status' => ClientAccount::STATUS_PAUSED,
            'email' => 'hidden@test.com',
            'paused_at' => now(),
        ]);

        $response = $this->getJson('/api/home-dashboard');

        $response->assertOk()
            ->assertJsonPath('paused_accounts', [])
            ->assertJsonPath('put_away_by_account', [])
            ->assertJsonPath('restock_preview', []);
    }
}
