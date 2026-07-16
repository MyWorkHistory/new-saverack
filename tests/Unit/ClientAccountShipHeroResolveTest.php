<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientAccountShipHeroResolveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('client_accounts');
        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->string('company_name')->nullable();
            $table->string('shiphero_customer_account_id')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('client_accounts');
        parent::tearDown();
    }

    public function test_resolve_prefers_active_account_for_shared_shiphero_id(): void
    {
        ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_INACTIVE,
            'company_name' => 'Audi Inactive',
            'shiphero_customer_account_id' => '12345',
        ]);
        $active = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Real Brand',
            'shiphero_customer_account_id' => '12345',
        ]);

        $resolved = ClientAccount::resolveByShipHeroCustomerId('12345');

        $this->assertNotNull($resolved);
        $this->assertSame((int) $active->id, (int) $resolved->id);
        $this->assertSame('Real Brand', $resolved->company_name);
    }

    public function test_operational_scope_excludes_inactive_linked_accounts(): void
    {
        ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_INACTIVE,
            'company_name' => 'Audi Inactive',
            'shiphero_customer_account_id' => '999',
        ]);
        ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Live Co',
            'shiphero_customer_account_id' => '888',
        ]);

        $names = ClientAccount::query()
            ->operationalForOrderDashboards()
            ->orderBy('company_name')
            ->pluck('company_name')
            ->all();

        $this->assertSame(['Live Co'], $names);
    }
}
