<?php

namespace Tests\Unit;

use App\Jobs\RefreshOrderDashboardSectionJob;
use App\Models\OrderDashboardSection;
use App\Services\OrderDashboardSnapshotService;
use App\Services\PortalQueueCountsService;
use App\Services\ShipHeroOrderQueueIndexService;
use App\Services\ShipHeroOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class OrderDashboardSnapshotServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('order_dashboard_sections');
        Schema::create('order_dashboard_sections', function (Blueprint $table) {
            $table->string('section_key', 64)->primary();
            $table->json('payload')->nullable();
            $table->unsignedInteger('total_count')->default(0);
            $table->string('status', 16)->default('idle');
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamp('refresh_started_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('order_dashboard_sections');
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_dashboard_payload_sums_hold_totals(): void
    {
        $now = now();
        foreach (
            [
                OrderDashboardSection::KEY_READY_TO_SHIP => 10,
                OrderDashboardSection::KEY_SHIPPED => 5,
                OrderDashboardSection::KEY_HOLD_OPERATOR => 2,
                OrderDashboardSection::KEY_HOLD_ADDRESS => 1,
                OrderDashboardSection::KEY_HOLD_FRAUD => 0,
                OrderDashboardSection::KEY_HOLD_PAYMENT => 3,
                OrderDashboardSection::KEY_HOLD_USER => 1,
                OrderDashboardSection::KEY_HOLD_BACKORDER => 4,
                OrderDashboardSection::KEY_ASN_PENDING => 7,
            ] as $key => $total
        ) {
            OrderDashboardSection::query()->insert([
                'section_key' => $key,
                'payload' => json_encode(['accounts' => [], 'truncated' => false]),
                'total_count' => $total,
                'status' => OrderDashboardSection::STATUS_IDLE,
                'refreshed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $orderIndex = Mockery::mock(ShipHeroOrderQueueIndexService::class);
        $orderIndex->shouldReceive('indexHasRowsForSection')
            ->with(OrderDashboardSection::KEY_HOLD_OPERATOR)
            ->andReturn(false);

        $service = new OrderDashboardSnapshotService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            $orderIndex
        );

        $payload = $service->getDashboardPayload();

        $this->assertSame(10, $payload['totals']['ready_to_ship']);
        $this->assertSame(5, $payload['totals']['shipped']);
        $this->assertSame(7, $payload['totals']['asn_pending']);
        $this->assertSame(7, $payload['totals']['on_hold']);
    }

    public function test_bootstrap_dispatches_shiphero_sections_and_marks_running(): void
    {
        Queue::fake();
        $now = now();
        foreach (OrderDashboardSection::ALL_KEYS as $key) {
            OrderDashboardSection::query()->insert([
                'section_key' => $key,
                'payload' => json_encode(['accounts' => [], 'truncated' => false]),
                'total_count' => 0,
                'status' => OrderDashboardSection::STATUS_IDLE,
                'refreshed_at' => $key === OrderDashboardSection::KEY_ASN_PENDING ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $service = new OrderDashboardSnapshotService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            Mockery::mock(ShipHeroOrderQueueIndexService::class)
        );

        $service->bootstrapIfNeeded();

        Queue::assertPushed(RefreshOrderDashboardSectionJob::class, count(OrderDashboardSection::SHIPHERO_KEYS));

        $running = OrderDashboardSection::query()
            ->whereIn('section_key', OrderDashboardSection::SHIPHERO_KEYS)
            ->where('status', OrderDashboardSection::STATUS_RUNNING)
            ->count();

        $this->assertSame(count(OrderDashboardSection::SHIPHERO_KEYS), $running);
    }

    public function test_refresh_section_uses_section_specific_index_gate(): void
    {
        $now = now();
        foreach (OrderDashboardSection::ALL_KEYS as $key) {
            OrderDashboardSection::query()->insert([
                'section_key' => $key,
                'payload' => json_encode(['accounts' => [], 'truncated' => false]),
                'total_count' => 0,
                'status' => OrderDashboardSection::STATUS_IDLE,
                'refreshed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $orderIndex = Mockery::mock(ShipHeroOrderQueueIndexService::class);
        $orderIndex->shouldNotReceive('syncAccountQueue');
        $orderIndex->shouldReceive('indexHasRowsForSection')
            ->with(OrderDashboardSection::KEY_SHIPPED)
            ->andReturn(false);
        $orderIndex->shouldNotReceive('aggregateDashboardSection');

        $orders = Mockery::mock(ShipHeroOrderService::class);
        $orders->shouldReceive('countShipments')
            ->once()
            ->andReturn(['count' => 212, 'truncated' => false]);

        $queueCounts = Mockery::mock(PortalQueueCountsService::class);
        $queueCounts->shouldReceive('contextForDashboardSection')
            ->andReturn([
                'customer_id' => 'sh-1',
                'timezone' => PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
                'shipped_from' => $now->copy()->startOfDay()->toIso8601String(),
                'shipped_to' => $now->endOfDay()->toIso8601String(),
                'open_from' => $now->copy()->subDays(29)->toIso8601String(),
                'open_to' => $now->endOfDay()->toIso8601String(),
            ]);

        Schema::dropIfExists('client_accounts');
        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
            $table->string('company_name')->nullable();
            $table->string('shiphero_customer_account_id')->nullable();
            $table->timestamps();
        });
        \App\Models\ClientAccount::query()->create([
            'status' => 'active',
            'company_name' => 'Gate Test Co',
            'shiphero_customer_account_id' => 'sh-1',
        ]);

        $service = new OrderDashboardSnapshotService(
            $queueCounts,
            $orders,
            $orderIndex
        );

        $service->refreshSection(OrderDashboardSection::KEY_SHIPPED);

        $this->assertSame(
            212,
            (int) OrderDashboardSection::query()
                ->where('section_key', OrderDashboardSection::KEY_SHIPPED)
                ->value('total_count')
        );
        $this->assertSame(
            OrderDashboardSection::STATUS_IDLE,
            OrderDashboardSection::query()
                ->where('section_key', OrderDashboardSection::KEY_SHIPPED)
                ->value('status')
        );
    }

    public function test_patch_account_updates_section_row_and_total(): void
    {
        $now = now();
        OrderDashboardSection::query()->insert([
            'section_key' => OrderDashboardSection::KEY_READY_TO_SHIP,
            'payload' => json_encode([
                'accounts' => [
                    [
                        'account_id' => 1,
                        'account_name' => 'Alpha Co',
                        'account_status' => 'active',
                        'orders_count' => 5,
                    ],
                    [
                        'account_id' => 2,
                        'account_name' => 'Beta Co',
                        'account_status' => 'active',
                        'orders_count' => 3,
                    ],
                ],
                'truncated' => false,
            ]),
            'total_count' => 8,
            'status' => OrderDashboardSection::STATUS_IDLE,
            'refreshed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::dropIfExists('client_accounts');
        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
            $table->string('company_name')->nullable();
            $table->string('shiphero_customer_account_id')->nullable();
            $table->timestamps();
        });
        \App\Models\ClientAccount::query()->create([
            'id' => 1,
            'status' => 'active',
            'company_name' => 'Alpha Co',
            'shiphero_customer_account_id' => 'sh-alpha',
        ]);

        $queueCounts = Mockery::mock(PortalQueueCountsService::class);
        $queueCounts->shouldReceive('contextForDashboardSection')
            ->once()
            ->andReturn(['customer_id' => 'sh-alpha']);

        $orderIndex = Mockery::mock(ShipHeroOrderQueueIndexService::class);
        $orderIndex->shouldReceive('countForDashboardSection')
            ->once()
            ->with(1, OrderDashboardSection::KEY_READY_TO_SHIP, ['customer_id' => 'sh-alpha'])
            ->andReturn(12);

        $service = new OrderDashboardSnapshotService(
            $queueCounts,
            Mockery::mock(ShipHeroOrderService::class),
            $orderIndex
        );

        $service->patchAccountFromQueueTab(1, 'awaiting');

        $row = OrderDashboardSection::query()
            ->where('section_key', OrderDashboardSection::KEY_READY_TO_SHIP)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(15, (int) $row->total_count);
        $accounts = is_array($row->payload) ? ($row->payload['accounts'] ?? []) : [];
        $this->assertCount(2, $accounts);
        $this->assertSame(12, (int) $accounts[0]['orders_count']);
        $this->assertSame('Alpha Co', $accounts[0]['account_name']);
        $this->assertSame(3, (int) $accounts[1]['orders_count']);
    }

    public function test_patch_account_removes_row_when_count_is_zero(): void
    {
        $now = now();
        OrderDashboardSection::query()->insert([
            'section_key' => OrderDashboardSection::KEY_SHIPPED,
            'payload' => json_encode([
                'accounts' => [
                    [
                        'account_id' => 9,
                        'account_name' => 'Solo Co',
                        'account_status' => 'active',
                        'orders_count' => 4,
                    ],
                ],
                'truncated' => false,
            ]),
            'total_count' => 4,
            'status' => OrderDashboardSection::STATUS_IDLE,
            'refreshed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::dropIfExists('client_accounts');
        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
            $table->string('company_name')->nullable();
            $table->string('shiphero_customer_account_id')->nullable();
            $table->timestamps();
        });
        \App\Models\ClientAccount::query()->create([
            'id' => 9,
            'status' => 'active',
            'company_name' => 'Solo Co',
            'shiphero_customer_account_id' => 'sh-solo',
        ]);

        $queueCounts = Mockery::mock(PortalQueueCountsService::class);
        $queueCounts->shouldReceive('contextForDashboardSection')
            ->once()
            ->andReturn(['customer_id' => 'sh-solo']);

        $orderIndex = Mockery::mock(ShipHeroOrderQueueIndexService::class);
        $orderIndex->shouldReceive('countForDashboardSection')
            ->once()
            ->with(9, OrderDashboardSection::KEY_SHIPPED, ['customer_id' => 'sh-solo'])
            ->andReturn(0);

        $service = new OrderDashboardSnapshotService(
            $queueCounts,
            Mockery::mock(ShipHeroOrderService::class),
            $orderIndex
        );

        $service->patchAccountFromQueueTab(9, 'shipped');

        $row = OrderDashboardSection::query()
            ->where('section_key', OrderDashboardSection::KEY_SHIPPED)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->total_count);
        $accounts = is_array($row->payload) ? ($row->payload['accounts'] ?? []) : [];
        $this->assertSame([], $accounts);
    }

    public function test_patch_account_skips_section_when_running(): void
    {
        $now = now();
        OrderDashboardSection::query()->insert([
            'section_key' => OrderDashboardSection::KEY_READY_TO_SHIP,
            'payload' => json_encode([
                'accounts' => [
                    [
                        'account_id' => 3,
                        'account_name' => 'Gamma Co',
                        'account_status' => 'active',
                        'orders_count' => 2,
                    ],
                ],
                'truncated' => false,
            ]),
            'total_count' => 2,
            'status' => OrderDashboardSection::STATUS_RUNNING,
            'refreshed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::dropIfExists('client_accounts');
        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
            $table->string('company_name')->nullable();
            $table->string('shiphero_customer_account_id')->nullable();
            $table->timestamps();
        });
        \App\Models\ClientAccount::query()->create([
            'id' => 3,
            'status' => 'active',
            'company_name' => 'Gamma Co',
            'shiphero_customer_account_id' => 'sh-gamma',
        ]);

        $queueCounts = Mockery::mock(PortalQueueCountsService::class);
        $orderIndex = Mockery::mock(ShipHeroOrderQueueIndexService::class);
        $orderIndex->shouldNotReceive('countForDashboardSection');

        $service = new OrderDashboardSnapshotService(
            $queueCounts,
            Mockery::mock(ShipHeroOrderService::class),
            $orderIndex
        );

        $service->patchAccountFromQueueTab(3, 'awaiting');

        $row = OrderDashboardSection::query()
            ->where('section_key', OrderDashboardSection::KEY_READY_TO_SHIP)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->total_count);
        $this->assertSame(OrderDashboardSection::STATUS_RUNNING, $row->status);
    }

    public function test_refresh_shipped_uses_index_when_populated(): void
    {
        $now = now();
        foreach (OrderDashboardSection::ALL_KEYS as $key) {
            OrderDashboardSection::query()->insert([
                'section_key' => $key,
                'payload' => json_encode(['accounts' => [], 'truncated' => false]),
                'total_count' => 0,
                'status' => OrderDashboardSection::STATUS_IDLE,
                'refreshed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $orderIndex = Mockery::mock(ShipHeroOrderQueueIndexService::class);
        $orderIndex->shouldReceive('indexHasRowsForSection')
            ->with(OrderDashboardSection::KEY_SHIPPED)
            ->andReturn(true);
        $orderIndex->shouldReceive('aggregateDashboardSection')
            ->once()
            ->with(OrderDashboardSection::KEY_SHIPPED, false)
            ->andReturn([
                'payload' => ['accounts' => [], 'truncated' => false],
                'total_count' => 279,
            ]);

        $orders = Mockery::mock(ShipHeroOrderService::class);
        $orders->shouldNotReceive('countShipments');

        $service = new OrderDashboardSnapshotService(
            Mockery::mock(PortalQueueCountsService::class),
            $orders,
            $orderIndex
        );

        $service->refreshSection(OrderDashboardSection::KEY_SHIPPED);

        $this->assertSame(
            279,
            (int) OrderDashboardSection::query()
                ->where('section_key', OrderDashboardSection::KEY_SHIPPED)
                ->value('total_count')
        );
    }

    public function test_on_hold_total_uses_distinct_index_count_when_populated(): void
    {
        $now = now();
        foreach (
            [
                OrderDashboardSection::KEY_READY_TO_SHIP => 0,
                OrderDashboardSection::KEY_SHIPPED => 0,
                OrderDashboardSection::KEY_HOLD_OPERATOR => 8,
                OrderDashboardSection::KEY_HOLD_ADDRESS => 5,
                OrderDashboardSection::KEY_HOLD_FRAUD => 0,
                OrderDashboardSection::KEY_HOLD_PAYMENT => 6,
                OrderDashboardSection::KEY_HOLD_USER => 4,
                OrderDashboardSection::KEY_HOLD_BACKORDER => 4,
                OrderDashboardSection::KEY_ASN_PENDING => 0,
            ] as $key => $total
        ) {
            OrderDashboardSection::query()->insert([
                'section_key' => $key,
                'payload' => json_encode(['accounts' => [], 'truncated' => false]),
                'total_count' => $total,
                'status' => OrderDashboardSection::STATUS_IDLE,
                'refreshed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $orderIndex = Mockery::mock(ShipHeroOrderQueueIndexService::class);
        $orderIndex->shouldReceive('indexHasRowsForSection')
            ->with(OrderDashboardSection::KEY_HOLD_OPERATOR)
            ->andReturn(true);
        $orderIndex->shouldReceive('aggregateDistinctOnHoldTotal')
            ->once()
            ->andReturn(13);

        $service = new OrderDashboardSnapshotService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            $orderIndex
        );

        $payload = $service->getDashboardPayload();

        $this->assertSame(13, $payload['totals']['on_hold']);
    }
}
