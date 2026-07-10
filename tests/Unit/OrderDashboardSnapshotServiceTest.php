<?php

namespace Tests\Unit;

use App\Jobs\RefreshOrderDashboardSectionJob;
use App\Jobs\RefreshPrimaryTotalsJob;
use App\Models\OrderDashboardSection;
use App\Services\OrderDashboardSnapshotService;
use App\Services\PortalQueueCountsService;
use App\Services\ShipHeroDashboardMetricsService;
use App\Services\ShipHeroOrderQueueIndexService;
use App\Services\ShipHeroOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class OrderDashboardSnapshotServiceTest extends TestCase
{
    /**
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    private function metricsResult(int $total): array
    {
        return [
            'payload' => ['accounts' => [], 'truncated' => false],
            'total_count' => $total,
        ];
    }

    private function makeService(
        PortalQueueCountsService $queueCounts,
        ShipHeroOrderService $orders,
        ShipHeroOrderQueueIndexService $orderIndex,
        ?ShipHeroDashboardMetricsService $dashboardMetrics = null
    ): OrderDashboardSnapshotService {
        return new OrderDashboardSnapshotService(
            $queueCounts,
            $orders,
            $orderIndex,
            $dashboardMetrics ?? Mockery::mock(ShipHeroDashboardMetricsService::class)
        );
    }

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
        $orderIndex->shouldReceive('indexIsHealthyForSection')->andReturn(false);
        $orderIndex->shouldReceive('indexHasRowsForSection')->andReturn(false);
        $orderIndex->shouldReceive('indexHasRowsForQueueTab')->andReturn(false);

        $metrics = Mockery::mock(ShipHeroDashboardMetricsService::class);
        $metrics->shouldReceive('cachedReadyToShipTotal')->andReturn(null);
        $metrics->shouldReceive('cachedShippedTodayTotal')->andReturn(null);
        $metrics->shouldReceive('cachedOnHoldTotal')->andReturn(7);

        $service = $this->makeService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            $orderIndex,
            $metrics
        );

        $payload = $service->getDashboardPayload();

        $this->assertSame(10, $payload['totals']['ready_to_ship']);
        $this->assertSame(5, $payload['totals']['shipped']);
        $this->assertSame(7, $payload['totals']['asn_pending']);
        $this->assertSame(7, $payload['totals']['on_hold']);
    }

    public function test_bootstrap_dispatches_primary_totals_job(): void
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

        $service = $this->makeService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            Mockery::mock(ShipHeroOrderQueueIndexService::class)
        );

        $service->bootstrapIfNeeded();

        Queue::assertPushed(RefreshPrimaryTotalsJob::class, 1);
    }

    public function test_get_dashboard_payload_uses_snapshot_totals_without_index_overlay(): void
    {
        $now = now();
        foreach (
            [
                OrderDashboardSection::KEY_READY_TO_SHIP => 6,
                OrderDashboardSection::KEY_SHIPPED => 233,
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
        $orderIndex->shouldReceive('indexIsHealthyForSection')->andReturn(false);
        $orderIndex->shouldNotReceive('aggregateDashboardSection');

        $metrics = Mockery::mock(ShipHeroDashboardMetricsService::class);
        $metrics->shouldReceive('cachedReadyToShipTotal')->andReturn(null);
        $metrics->shouldReceive('cachedShippedTodayTotal')->andReturn(null);
        $metrics->shouldReceive('cachedOnHoldTotal')->andReturn(null);

        $service = $this->makeService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            $orderIndex,
            $metrics
        );

        $payload = $service->getDashboardPayload();

        $this->assertSame(6, $payload['totals']['ready_to_ship']);
        $this->assertSame(233, $payload['totals']['shipped']);
    }

    public function test_refresh_section_uses_live_shipped_metrics(): void
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
        $orderIndex->shouldNotReceive('aggregateDashboardSection');

        $metrics = Mockery::mock(ShipHeroDashboardMetricsService::class);
        $metrics->shouldReceive('aggregateShippedToday')
            ->once()
            ->with(false)
            ->andReturn($this->metricsResult(212));
        $metrics->shouldReceive('putLiveMetricCache')
            ->once()
            ->with('shipped_today', 212);
        $metrics->shouldReceive('clearCacheForToday')->once();

        $service = $this->makeService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            $orderIndex,
            $metrics
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

    public function test_patch_account_does_not_mutate_dashboard_snapshot(): void
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

        $service = $this->makeService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            Mockery::mock(ShipHeroOrderQueueIndexService::class)
        );

        $service->patchAccountFromQueueTab(1, 'awaiting');

        $row = OrderDashboardSection::query()
            ->where('section_key', OrderDashboardSection::KEY_READY_TO_SHIP)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(8, (int) $row->total_count);
    }

    public function test_patch_account_leaves_zero_count_snapshot_unchanged(): void
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

        $service = $this->makeService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            Mockery::mock(ShipHeroOrderQueueIndexService::class)
        );

        $service->patchAccountFromQueueTab(9, 'shipped');

        $row = OrderDashboardSection::query()
            ->where('section_key', OrderDashboardSection::KEY_SHIPPED)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(4, (int) $row->total_count);
    }

    public function test_patch_account_leaves_running_section_unchanged(): void
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

        $service = $this->makeService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            Mockery::mock(ShipHeroOrderQueueIndexService::class)
        );

        $service->patchAccountFromQueueTab(3, 'awaiting');

        $row = OrderDashboardSection::query()
            ->where('section_key', OrderDashboardSection::KEY_READY_TO_SHIP)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->total_count);
        $this->assertSame(OrderDashboardSection::STATUS_RUNNING, $row->status);
    }

    public function test_refresh_shipped_uses_live_metrics(): void
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

        $metrics = Mockery::mock(ShipHeroDashboardMetricsService::class);
        $metrics->shouldReceive('aggregateShippedToday')
            ->once()
            ->with(false)
            ->andReturn($this->metricsResult(279));
        $metrics->shouldReceive('putLiveMetricCache')
            ->once()
            ->with('shipped_today', 279);
        $metrics->shouldReceive('clearCacheForToday')->once();

        $service = $this->makeService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            Mockery::mock(ShipHeroOrderQueueIndexService::class),
            $metrics
        );

        $service->refreshSection(OrderDashboardSection::KEY_SHIPPED);

        $this->assertSame(
            279,
            (int) OrderDashboardSection::query()
                ->where('section_key', OrderDashboardSection::KEY_SHIPPED)
                ->value('total_count')
        );
    }

    public function test_on_hold_total_uses_live_metrics(): void
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
        $orderIndex->shouldReceive('indexIsHealthyForSection')->andReturn(false);

        $metrics = Mockery::mock(ShipHeroDashboardMetricsService::class);
        $metrics->shouldReceive('cachedReadyToShipTotal')->andReturn(null);
        $metrics->shouldReceive('cachedShippedTodayTotal')->andReturn(null);
        $metrics->shouldReceive('cachedOnHoldTotal')->andReturn(78);

        $service = $this->makeService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            $orderIndex,
            $metrics
        );

        $payload = $service->getDashboardPayload();

        $this->assertSame(78, $payload['totals']['on_hold']);
    }
}
