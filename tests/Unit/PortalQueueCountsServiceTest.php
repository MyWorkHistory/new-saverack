<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Services\PortalQueueCountsService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
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
}
