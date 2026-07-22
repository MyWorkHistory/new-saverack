<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\PricingFeeTemplate;
use App\Services\ClientAccountService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FeesPayloadPostageVisibilityTest extends TestCase
{
    /** @var ClientAccountService */
    private $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::connection('sqlite')->dropIfExists('client_account_fees');
        Schema::connection('sqlite')->dropIfExists('client_accounts');
        Schema::connection('sqlite')->dropIfExists('pricing_fee_templates');

        Schema::connection('sqlite')->create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('invoice_share_slug')->nullable();
            $table->timestamps();
        });
        Schema::connection('sqlite')->create('pricing_fee_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('category')->nullable();
            $table->decimal('amount', 12, 4)->nullable();
            $table->decimal('cost', 12, 4)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::connection('sqlite')->create('client_account_fees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_account_id');
            $table->unsignedBigInteger('pricing_template_id')->nullable();
            $table->string('fee_group')->nullable();
            $table->string('line_code')->nullable();
            $table->string('label')->nullable();
            $table->string('description')->nullable();
            $table->string('icon_path')->nullable();
            $table->decimal('amount', 12, 4)->nullable();
            $table->decimal('cost', 12, 4)->nullable();
            $table->string('currency')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        $this->service = app(ClientAccountService::class);
    }

    public function test_staff_payload_includes_postage_client_facing_excludes_it(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Visibility Co',
            'email' => 'visibility@example.test',
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_FULFILLMENT,
            'line_code' => 'first_pick',
            'label' => 'First Pick',
            'amount' => 1.5,
            'currency' => 'USD',
            'sort_order' => 0,
        ]);
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => PricingFeeTemplate::CATEGORY_POSTAGE,
            'line_code' => 'postage_usps',
            'label' => 'USPS',
            'amount' => 0.25,
            'currency' => 'USD',
            'sort_order' => 1,
        ]);

        $account = $account->fresh(['feeItems']);

        $staff = $this->service->feesPayloadForApi($account, true, false);
        $staffCats = array_column($staff['items'] ?? [], 'category');
        $this->assertContains(ClientAccountFee::GROUP_FULFILLMENT, $staffCats);
        $this->assertContains(PricingFeeTemplate::CATEGORY_POSTAGE, $staffCats);
        $postage = collect($staff['items'])->firstWhere('category', PricingFeeTemplate::CATEGORY_POSTAGE);
        $this->assertArrayHasKey('cost', $postage);

        $client = $this->service->feesPayloadForApi($account, false, true);
        $clientCats = array_column($client['items'] ?? [], 'category');
        $this->assertContains(ClientAccountFee::GROUP_FULFILLMENT, $clientCats);
        $this->assertNotContains(PricingFeeTemplate::CATEGORY_POSTAGE, $clientCats);
        foreach ($client['items'] as $row) {
            $this->assertArrayNotHasKey('cost', $row);
        }
    }
}
