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

        $service = new OrderDashboardSnapshotService(
            Mockery::mock(PortalQueueCountsService::class),
            Mockery::mock(ShipHeroOrderService::class),
            Mockery::mock(ShipHeroOrderQueueIndexService::class)
        );

        $payload = $service->getDashboardPayload();

        $this->assertSame(10, $payload['totals']['ready_to_ship']);
        $this->assertSame(5, $payload['totals']['shipped']);
        $this->assertSame(7, $payload['totals']['asn_pending']);
        $this->assertSame(11, $payload['totals']['on_hold']);
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
}
