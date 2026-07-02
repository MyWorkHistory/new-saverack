<?php

namespace Tests\Unit;

use App\Models\ShipHeroOrderQueueIndex;
use App\Services\PortalQueueCountsService;
use App\Services\ShipHeroOrderQueueIndexService;
use App\Services\ShipHeroOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ShipHeroOrderQueueIndexServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('shiphero_order_queue_index');
        Schema::create('shiphero_order_queue_index', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_account_id');
            $table->string('shiphero_order_id', 191);
            $table->string('queue_kind', 32);
            $table->string('hold_reason', 32)->nullable();
            $table->boolean('ready_to_ship')->default(false);
            $table->boolean('has_backorder')->default(false);
            $table->string('order_number', 128)->nullable();
            $table->string('order_number_search', 128)->nullable();
            $table->string('recipient_name', 255)->nullable();
            $table->timestamp('order_date')->nullable();
            $table->timestamp('ship_date')->nullable();
            $table->string('country', 64)->nullable();
            $table->string('display_status', 64)->nullable();
            $table->json('list_payload')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shiphero_order_queue_index');
        Mockery::close();
        parent::tearDown();
    }

    public function test_upsert_rows_and_list_from_index(): void
    {
        $service = new ShipHeroOrderQueueIndexService(
            Mockery::mock(ShipHeroOrderService::class),
            Mockery::mock(PortalQueueCountsService::class)
        );

        $service->upsertRows(12, ShipHeroOrderQueueIndex::KIND_AWAITING, [
            [
                'id' => 'order-abc',
                'order_number' => '#1001',
                'recipient_name' => 'Jane Doe',
                'order_date' => now()->toIso8601String(),
                'country' => 'US',
                'display_status' => 'Ready To Ship',
                'holds' => [],
                'has_backorder' => false,
            ],
        ]);

        $this->assertTrue($service->indexHasRows(12, ShipHeroOrderQueueIndex::KIND_AWAITING));

        $row = ShipHeroOrderQueueIndex::query()
            ->where('client_account_id', 12)
            ->where('shiphero_order_id', 'order-abc')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Jane Doe', $row->recipient_name);
        $this->assertSame('#1001', $row->order_number);
    }

    public function test_aggregate_dashboard_section_groups_by_account(): void
    {
        $now = now();
        ShipHeroOrderQueueIndex::query()->insert([
            'client_account_id' => 5,
            'shiphero_order_id' => 'o-1',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_AWAITING,
            'order_number' => 'A1',
            'list_payload' => json_encode(['id' => 'o-1']),
            'indexed_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        ShipHeroOrderQueueIndex::query()->insert([
            'client_account_id' => 5,
            'shiphero_order_id' => 'o-2',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_AWAITING,
            'order_number' => 'A2',
            'list_payload' => json_encode(['id' => 'o-2']),
            'indexed_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = new ShipHeroOrderQueueIndexService(
            Mockery::mock(ShipHeroOrderService::class),
            Mockery::mock(PortalQueueCountsService::class)
        );

        $result = $service->aggregateDashboardSection('ready_to_ship');

        $this->assertSame(2, $result['total_count']);
        $this->assertCount(1, $result['payload']['accounts']);
        $this->assertSame(5, $result['payload']['accounts'][0]['account_id']);
        $this->assertSame(2, $result['payload']['accounts'][0]['orders_count']);
    }

    public function test_count_for_account_tab_respects_open_queue_window(): void
    {
        $now = now();
        $context = [
            'awaiting_from' => $now->copy()->subDays(6)->toIso8601String(),
            'awaiting_to' => $now->toIso8601String(),
            'open_from' => $now->copy()->subDays(29)->startOfDay()->toIso8601String(),
            'open_to' => $now->endOfDay()->toIso8601String(),
            'shipped_from' => $now->copy()->startOfDay()->toIso8601String(),
            'shipped_to' => $now->endOfDay()->toIso8601String(),
        ];

        ShipHeroOrderQueueIndex::query()->insert([
            'client_account_id' => 9,
            'shiphero_order_id' => 'old-bo',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_BACKORDER,
            'order_date' => $now->copy()->subDays(40),
            'list_payload' => json_encode(['id' => 'old-bo']),
            'indexed_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        ShipHeroOrderQueueIndex::query()->insert([
            'client_account_id' => 9,
            'shiphero_order_id' => 'recent-bo',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_BACKORDER,
            'order_date' => $now->copy()->subDays(10),
            'list_payload' => json_encode(['id' => 'recent-bo']),
            'indexed_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = new ShipHeroOrderQueueIndexService(
            Mockery::mock(ShipHeroOrderService::class),
            Mockery::mock(PortalQueueCountsService::class)
        );

        $this->assertSame(1, $service->countForAccountTab(9, ShipHeroOrderQueueIndex::KIND_BACKORDER, $context));
    }
}
