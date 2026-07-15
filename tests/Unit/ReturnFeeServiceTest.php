<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\ClientAccountReturn;
use App\Models\PricingFeeTemplate;
use App\Services\ReturnFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnFeeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_defaults_resolve_template_line_codes_by_label(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Return Fee Co',
            'email' => 'return-fee@example.test',
        ]);

        $template = PricingFeeTemplate::query()->create([
            'name' => 'Returns Processing',
            'description' => null,
            'category' => PricingFeeTemplate::CATEGORY_RETURNS,
            'amount' => '8.5000',
            'sort_order' => 1,
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'pricing_template_id' => $template->id,
            'fee_group' => ClientAccountFee::GROUP_RETURNS,
            'line_code' => 'template_'.$template->id,
            'label' => 'Returns Processing',
            'amount' => '8.5000',
            'currency' => 'USD',
            'sort_order' => 1,
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RETURNS,
            'line_code' => 'template_999',
            'label' => 'Returns Additional Items',
            'amount' => '2.2500',
            'currency' => 'USD',
            'sort_order' => 2,
        ]);

        $defaults = app(ReturnFeeService::class)->accountDefaults(
            $account->fresh(['feeItems.pricingTemplate'])
        );

        $this->assertSame(8.5, $defaults['first_item']);
        $this->assertSame(2.25, $defaults['additional_item']);
    }

    public function test_seed_fills_null_fees_from_account_defaults(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Seed Fee Co',
            'email' => 'seed-fee@example.test',
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RETURNS,
            'line_code' => ClientAccountFee::LINE_RETURNS_PROCESSING,
            'amount' => '6.0000',
            'currency' => 'USD',
            'sort_order' => 0,
        ]);

        $return = ClientAccountReturn::query()->create([
            'client_account_id' => $account->id,
            'rma_number' => 'RMA1001',
            'status' => ClientAccountReturn::STATUS_PENDING,
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'shiphero_order_id' => 'sh-1',
            'order_number' => 'ORD-1',
            'customer_name' => 'Customer',
            'items_count' => 1,
            'created_source' => ClientAccountReturn::SOURCE_PORTAL,
        ]);

        app(ReturnFeeService::class)->seedReturnFees($return);

        $this->assertSame('6.0000', (string) $return->fresh()->return_fee_first_item);
    }
}
