<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\OrderDashboardSection;
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
        Schema::dropIfExists('client_accounts');
        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
            $table->string('company_name')->nullable();
            $table->string('shiphero_customer_account_id')->nullable();
            $table->timestamps();
        });
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
        Schema::dropIfExists('client_accounts');
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardContext(): array
    {
        $now = now();

        return [
            'awaiting_from' => $now->copy()->subDays(6)->startOfDay()->toIso8601String(),
            'awaiting_to' => $now->endOfDay()->toIso8601String(),
            'open_from' => $now->copy()->subDays(29)->startOfDay()->toIso8601String(),
            'open_to' => $now->endOfDay()->toIso8601String(),
            'shipped_from' => $now->copy()->startOfDay()->toIso8601String(),
            'shipped_to' => $now->endOfDay()->toIso8601String(),
        ];
    }

    private function createLinkedAccount(string $name = 'Test Co'): ClientAccount
    {
        return ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => $name,
            'shiphero_customer_account_id' => 'sh-'.uniqid('', true),
        ]);
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
        $account = $this->createLinkedAccount('Home Dash Co');
        $accountId = (int) $account->id;

        $queueCounts = Mockery::mock(PortalQueueCountsService::class);
        $queueCounts->shouldReceive('contextForDashboardSection')
            ->andReturnUsing(function ($account, $sectionKey) {
                return $this->dashboardContext();
            });

        ShipHeroOrderQueueIndex::query()->insert([
            'client_account_id' => $accountId,
            'shiphero_order_id' => 'o-1',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_AWAITING,
            'order_number' => 'A1',
            'order_date' => $now,
            'list_payload' => json_encode(['id' => 'o-1']),
            'indexed_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        ShipHeroOrderQueueIndex::query()->insert([
            'client_account_id' => $accountId,
            'shiphero_order_id' => 'o-2',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_AWAITING,
            'order_number' => 'A2',
            'order_date' => $now,
            'list_payload' => json_encode(['id' => 'o-2']),
            'indexed_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = new ShipHeroOrderQueueIndexService(
            Mockery::mock(ShipHeroOrderService::class),
            $queueCounts
        );

        $result = $service->aggregateDashboardSection('ready_to_ship');

        $this->assertSame(2, $result['total_count']);
        $this->assertCount(1, $result['payload']['accounts']);
        $this->assertSame($accountId, $result['payload']['accounts'][0]['account_id']);
        $this->assertSame(2, $result['payload']['accounts'][0]['orders_count']);
        $this->assertSame('Home Dash Co', $result['payload']['accounts'][0]['account_name']);
    }

    public function test_index_has_rows_for_section_only_checks_matching_queue_kind(): void
    {
        $now = now();
        ShipHeroOrderQueueIndex::query()->insert([
            'client_account_id' => 1,
            'shiphero_order_id' => 'awaiting-only',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_AWAITING,
            'order_date' => $now,
            'list_payload' => json_encode(['id' => 'awaiting-only']),
            'indexed_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = new ShipHeroOrderQueueIndexService(
            Mockery::mock(ShipHeroOrderService::class),
            Mockery::mock(PortalQueueCountsService::class)
        );

        $this->assertTrue($service->indexHasRowsForSection('ready_to_ship'));
        $this->assertFalse($service->indexHasRowsForSection('shipped'));
        $this->assertFalse($service->indexHasRowsForSection('hold_operator'));
    }

    public function test_aggregate_dashboard_section_excludes_orders_outside_awaiting_window(): void
    {
        $now = now();
        $account = $this->createLinkedAccount();
        $accountId = (int) $account->id;

        $queueCounts = Mockery::mock(PortalQueueCountsService::class);
        $queueCounts->shouldReceive('contextForDashboardSection')
            ->andReturn($this->dashboardContext());

        ShipHeroOrderQueueIndex::query()->insert([
            'client_account_id' => $accountId,
            'shiphero_order_id' => 'recent',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_AWAITING,
            'order_date' => $now,
            'list_payload' => json_encode(['id' => 'recent']),
            'indexed_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        ShipHeroOrderQueueIndex::query()->insert([
            'client_account_id' => $accountId,
            'shiphero_order_id' => 'stale',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_AWAITING,
            'order_date' => $now->copy()->subDays(20),
            'list_payload' => json_encode(['id' => 'stale']),
            'indexed_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = new ShipHeroOrderQueueIndexService(
            Mockery::mock(ShipHeroOrderService::class),
            $queueCounts
        );

        $result = $service->aggregateDashboardSection('ready_to_ship');

        $this->assertSame(1, $result['total_count']);
    }

    public function test_aggregate_dashboard_section_shipped_only_counts_today(): void
    {
        $now = now();
        $account = $this->createLinkedAccount();
        $accountId = (int) $account->id;

        $queueCounts = Mockery::mock(PortalQueueCountsService::class);
        $queueCounts->shouldReceive('contextForDashboardSection')
            ->andReturn($this->dashboardContext());

        ShipHeroOrderQueueIndex::query()->insert([
            'client_account_id' => $accountId,
            'shiphero_order_id' => 'shipped-today',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_SHIPPED,
            'ship_date' => $now,
            'list_payload' => json_encode(['id' => 'shipped-today']),
            'indexed_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        ShipHeroOrderQueueIndex::query()->insert([
            'client_account_id' => $accountId,
            'shiphero_order_id' => 'shipped-old',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_SHIPPED,
            'ship_date' => $now->copy()->subDays(3),
            'list_payload' => json_encode(['id' => 'shipped-old']),
            'indexed_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = new ShipHeroOrderQueueIndexService(
            Mockery::mock(ShipHeroOrderService::class),
            $queueCounts
        );

        $result = $service->aggregateDashboardSection('shipped');

        $this->assertSame(1, $result['total_count']);
    }

    public function test_hold_section_aggregate_respects_hold_reason(): void
    {
        $now = now();
        $account = $this->createLinkedAccount();
        $accountId = (int) $account->id;

        $queueCounts = Mockery::mock(PortalQueueCountsService::class);
        $queueCounts->shouldReceive('contextForDashboardSection')
            ->andReturn($this->dashboardContext());

        foreach (['operator', 'payment'] as $reason) {
            ShipHeroOrderQueueIndex::query()->insert([
                'client_account_id' => $accountId,
                'shiphero_order_id' => 'hold-'.$reason,
                'queue_kind' => ShipHeroOrderQueueIndex::KIND_ON_HOLD,
                'hold_reason' => $reason,
                'order_date' => $now,
                'list_payload' => json_encode(['id' => 'hold-'.$reason]),
                'indexed_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $service = new ShipHeroOrderQueueIndexService(
            Mockery::mock(ShipHeroOrderService::class),
            $queueCounts
        );

        $operator = $service->aggregateDashboardSection('hold_operator');
        $payment = $service->aggregateDashboardSection('hold_payment');

        $this->assertSame(1, $operator['total_count']);
        $this->assertSame(1, $payment['total_count']);
    }

    public function test_hold_section_matches_secondary_hold_in_payload(): void
    {
        $now = now();
        $account = $this->createLinkedAccount();
        $accountId = (int) $account->id;

        $queueCounts = Mockery::mock(PortalQueueCountsService::class);
        $queueCounts->shouldReceive('contextForDashboardSection')
            ->andReturn($this->dashboardContext());

        ShipHeroOrderQueueIndex::query()->insert([
            'client_account_id' => $accountId,
            'shiphero_order_id' => 'hold-multi',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_ON_HOLD,
            'hold_reason' => 'fraud',
            'order_date' => $now,
            'list_payload' => json_encode([
                'id' => 'hold-multi',
                'holds' => [
                    'fraud_hold' => true,
                    'payment_hold' => true,
                ],
            ]),
            'indexed_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = new ShipHeroOrderQueueIndexService(
            Mockery::mock(ShipHeroOrderService::class),
            $queueCounts
        );

        $payment = $service->aggregateDashboardSection('hold_payment');
        $fraud = $service->aggregateDashboardSection('hold_fraud');

        $this->assertSame(1, $payment['total_count']);
        $this->assertSame(1, $fraud['total_count']);
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
