<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Services\PortalQueueCountsService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PortalQueueCountsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('client_accounts');
        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
            $table->string('company_name')->nullable();
            $table->string('shiphero_customer_account_id')->nullable();
            $table->string('timezone')->nullable();
            $table->timestamps();
        });
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
            $table->timestamp('order_date')->nullable();
            $table->json('list_payload')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_context_for_account_defaults_shipped_to_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'America/New_York'));

        $account = ClientAccount::create([
            'company_name' => 'Shipped Window Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-shipped-window',
        ]);

        $service = app(PortalQueueCountsService::class);
        $context = $service->contextForAccount($account);

        $todayStart = Carbon::parse('2026-07-01', 'America/New_York')->startOfDay()->toIso8601String();
        $todayEnd = Carbon::parse('2026-07-01', 'America/New_York')->endOfDay()->toIso8601String();

        $this->assertSame($todayStart, $context['shipped_from']);
        $this->assertSame($todayEnd, $context['shipped_to']);
        $this->assertNotSame($context['open_from'], $context['shipped_from']);
    }

    public function test_count_tab_from_index_backorder_uses_feb_first_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'America/New_York'));

        $account = ClientAccount::create([
            'company_name' => 'Backorder Window Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-backorder',
        ]);

        DB::table('shiphero_order_queue_index')->insert([
            'client_account_id' => $account->id,
            'queue_kind' => 'backorder',
            'shiphero_order_id' => 'bo-old-1',
            'order_number_search' => '2001',
            'order_date' => Carbon::parse('2026-03-15 10:00:00', 'America/New_York'),
            'list_payload' => json_encode(['id' => 'bo-old-1']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(PortalQueueCountsService::class);
        $context = $service->contextForAccount($account);
        $result = $service->countTabFromIndex($context, 'backorder');

        $this->assertNotNull($result);
        $this->assertSame(1, $result['count']);
    }
}
