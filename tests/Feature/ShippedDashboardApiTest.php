<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\OrderDashboardSection;
use App\Models\Permission;
use App\Models\ShipHeroOrderQueueIndex;
use App\Models\ShippedDaySnapshot;
use App\Models\User;
use App\Services\PortalQueueCountsService;
use App\Services\ShippedDaySnapshotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShippedDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingOrdersStaff(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $perm = Permission::query()->firstOrCreate(
            ['key' => 'orders.view'],
            ['label' => 'View orders', 'module' => 'orders']
        );
        $user->permissions()->attach($perm->id);
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeAccount(string $name, string $customerId): ClientAccount
    {
        return ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'@example.test',
            'shiphero_customer_account_id' => $customerId,
        ]);
    }

    private function seedShippedIndexRow(
        ClientAccount $account,
        string $orderId,
        Carbon $shipDate,
        int $labelCount
    ): void {
        ShipHeroOrderQueueIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_order_id' => $orderId,
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_SHIPPED,
            'ready_to_ship' => false,
            'has_backorder' => false,
            'ship_date' => $shipDate,
            'list_payload' => [
                'id' => $orderId,
                'shipped_label_count' => $labelCount,
            ],
            'indexed_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    public function test_guest_cannot_access_shipped_dashboard(): void
    {
        $this->getJson('/api/orders/shipped-dashboard')->assertUnauthorized();
        $this->postJson('/api/orders/shipped-dashboard/refresh')->assertUnauthorized();
    }

    public function test_snapshot_command_upserts_day_and_accounts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 15:00:00', 'America/New_York'));

        $account = $this->makeAccount('Snap Co', 'sh-snap-1');
        $dayStart = Carbon::parse('2026-07-29 10:00:00', 'America/New_York');
        $this->seedShippedIndexRow($account, 'ord-snap-1', $dayStart, 5);
        $this->seedShippedIndexRow($account, 'ord-snap-2', $dayStart->copy()->addHour(), 2);

        Artisan::call('orders:snapshot-shipped-day', ['--date' => '2026-07-29']);

        $this->assertDatabaseCount('shipped_day_snapshots', 1);
        $this->assertDatabaseHas('shipped_day_snapshots', [
            'snapshot_date' => '2026-07-29',
            'total_count' => 7,
        ]);
        $this->assertDatabaseCount('shipped_day_snapshot_accounts', 1);
        $this->assertDatabaseHas('shipped_day_snapshot_accounts', [
            'client_account_id' => $account->id,
            'orders_count' => 7,
        ]);

        // Regenerate replaces accounts
        ShipHeroOrderQueueIndex::query()->delete();
        $this->seedShippedIndexRow($account, 'ord-snap-3', $dayStart, 3);
        Artisan::call('orders:snapshot-shipped-day', ['--date' => '2026-07-29']);

        $this->assertDatabaseCount('shipped_day_snapshots', 1);
        $this->assertDatabaseHas('shipped_day_snapshots', [
            'snapshot_date' => '2026-07-29',
            'total_count' => 3,
        ]);
        $this->assertDatabaseCount('shipped_day_snapshot_accounts', 1);
        $this->assertDatabaseHas('shipped_day_snapshot_accounts', [
            'client_account_id' => $account->id,
            'orders_count' => 3,
        ]);

        Carbon::setTestNow();
    }

    public function test_dashboard_prefers_yesterday_snapshot_and_sums_periods(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'America/New_York'));
        $this->actingOrdersStaff();

        $account = $this->makeAccount('Dash Co', 'sh-dash-1');

        // Live today section (dashboard snapshot table)
        OrderDashboardSection::query()->updateOrCreate(
            ['section_key' => OrderDashboardSection::KEY_SHIPPED],
            [
                'payload' => [
                    'accounts' => [
                        [
                            'account_id' => $account->id,
                            'account_name' => $account->company_name,
                            'account_status' => ClientAccount::STATUS_ACTIVE,
                            'orders_count' => 10,
                        ],
                    ],
                    'truncated' => false,
                ],
                'total_count' => 10,
                'status' => OrderDashboardSection::STATUS_IDLE,
                'refreshed_at' => now(),
            ]
        );

        // Ensure other primary sections exist so getDashboardPayload does not fail oddly
        foreach (
            [
                OrderDashboardSection::KEY_READY_TO_SHIP,
                OrderDashboardSection::KEY_ON_HOLD,
                OrderDashboardSection::KEY_HOLD_OPERATOR,
                OrderDashboardSection::KEY_HOLD_ADDRESS,
                OrderDashboardSection::KEY_HOLD_FRAUD,
                OrderDashboardSection::KEY_HOLD_PAYMENT,
                OrderDashboardSection::KEY_HOLD_USER,
                OrderDashboardSection::KEY_HOLD_BACKORDER,
                OrderDashboardSection::KEY_ASN_PENDING,
            ] as $key
        ) {
            OrderDashboardSection::query()->firstOrCreate(
                ['section_key' => $key],
                [
                    'payload' => ['accounts' => [], 'truncated' => false],
                    'total_count' => 0,
                    'status' => OrderDashboardSection::STATUS_IDLE,
                ]
            );
        }

        // Yesterday snapshot (prefer over index)
        $snapshot = ShippedDaySnapshot::query()->create([
            'snapshot_date' => '2026-07-28',
            'total_count' => 4,
            'captured_at' => now(),
            'timezone' => PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
        ]);
        $snapshot->accounts()->create([
            'client_account_id' => $account->id,
            'account_name' => $account->company_name,
            'orders_count' => 4,
        ]);

        // Index has a different yesterday count — dashboard must use snapshot (4), not index
        $this->seedShippedIndexRow(
            $account,
            'ord-yest-index',
            Carbon::parse('2026-07-28 14:00:00', 'America/New_York'),
            99
        );

        $response = $this->getJson('/api/orders/shipped-dashboard');
        $response->assertOk()
            ->assertJsonPath('totals.today', 10)
            ->assertJsonPath('totals.yesterday', 4)
            ->assertJsonPath('yesterday.from_snapshot', true)
            ->assertJsonPath('yesterday.total_count', 4)
            ->assertJsonPath('today.total_count', 10)
            ->assertJsonCount(1, 'yesterday.accounts');

        // This week = Mon 7/27 .. Sun 8/2: need Monday snapshot too for sum
        // Without Mon snapshot, index fallback may add more; seed Mon as 0 via empty snapshot
        ShippedDaySnapshot::query()->create([
            'snapshot_date' => '2026-07-27',
            'total_count' => 1,
            'captured_at' => now(),
            'timezone' => PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
        ]);

        $again = $this->getJson('/api/orders/shipped-dashboard');
        $again->assertOk();
        // this_week = Mon(1) + Tue(4 snapshot) + Wed today(10) = 15 (other days 0 from empty index)
        $this->assertSame(15, (int) $again->json('totals.this_week'));
        $this->assertSame(10, (int) $again->json('totals.today'));
        $this->assertSame(4, (int) $again->json('totals.yesterday'));

        Carbon::setTestNow();
    }

    public function test_capture_day_via_service_matches_index_labels(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 23:00:00', 'America/New_York'));

        $a = $this->makeAccount('A Co', 'sh-a');
        $b = $this->makeAccount('B Co', 'sh-b');
        $shipAt = Carbon::parse('2026-07-29 09:00:00', 'America/New_York');
        $this->seedShippedIndexRow($a, 'ord-a', $shipAt, 3);
        $this->seedShippedIndexRow($b, 'ord-b', $shipAt->copy()->addHours(2), 2);

        /** @var ShippedDaySnapshotService $service */
        $service = app(ShippedDaySnapshotService::class);
        $row = $service->captureDay();

        $this->assertSame(5, (int) $row->total_count);
        $this->assertCount(2, $row->accounts);

        Carbon::setTestNow();
    }
}
