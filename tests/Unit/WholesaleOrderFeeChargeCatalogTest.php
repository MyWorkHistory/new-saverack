<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\PricingFeeTemplate;
use App\Support\Billing\WholesaleOrderFeeChargeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WholesaleOrderFeeChargeCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_options_use_dynamic_wholesale_and_packaging_fees(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Wholesale Fees Co',
            'email' => 'wholesale-fees@example.test',
        ]);
        $wholesale = ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => PricingFeeTemplate::CATEGORY_WHOLESALE,
            'line_code' => 'wholesale_custom',
            'label' => 'Marketplace Prep',
            'amount' => '2.5000',
            'currency' => 'USD',
            'sort_order' => 1,
        ]);
        $packaging = ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => PricingFeeTemplate::CATEGORY_PACKAGING,
            'line_code' => 'packaging_custom',
            'label' => 'Large Carton',
            'amount' => '4.7500',
            'currency' => 'USD',
            'sort_order' => 2,
        ]);

        $options = WholesaleOrderFeeChargeCatalog::optionsForAccount($account);
        $this->assertCount(1, $options);
        $this->assertSame($wholesale->id, $options[0]['client_account_fee_id']);
        $this->assertSame('Marketplace Prep', $options[0]['display_name']);
        $this->assertSame(250, $options[0]['default_unit_price_cents']);

        $packagingOptions = WholesaleOrderFeeChargeCatalog::packagingOptionsForAccount($account);
        $this->assertCount(1, $packagingOptions);
        $this->assertSame('fee_'.$packaging->id, $packagingOptions[0]['line_type']);
        $this->assertSame(475, $packagingOptions[0]['default_unit_price_cents']);
    }

    public function test_wholesale_box_fee_appears_in_packaging_dropdown(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Box Fee Co',
            'email' => 'box-fee@example.test',
        ]);
        $box = ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => PricingFeeTemplate::CATEGORY_WHOLESALE,
            'line_code' => 'box_price',
            'label' => 'Box',
            'amount' => '1.2500',
            'currency' => 'USD',
            'sort_order' => 1,
        ]);

        $packagingOptions = WholesaleOrderFeeChargeCatalog::packagingOptionsForAccount($account);
        $this->assertCount(1, $packagingOptions);
        $this->assertSame($box->id, $packagingOptions[0]['client_account_fee_id']);
        $this->assertSame('Box', $packagingOptions[0]['display_name']);
        $this->assertSame(125, $packagingOptions[0]['default_unit_price_cents']);
    }

    public function test_dynamic_fee_and_custom_line_types_are_valid(): void
    {
        $this->assertTrue(WholesaleOrderFeeChargeCatalog::isValidLineType('fee_123'));
        $this->assertTrue(WholesaleOrderFeeChargeCatalog::isValidLineType('custom_abc123'));
        $this->assertFalse(WholesaleOrderFeeChargeCatalog::isValidLineType('invalid line'));
    }

    public function test_default_options_includes_standard_wholesale_fees(): void
    {
        $options = WholesaleOrderFeeChargeCatalog::defaultOptions();
        $this->assertNotEmpty($options);
        $types = array_column($options, 'line_type');
        $this->assertContains('wholesale_fulfillment', $types);
        $this->assertContains('master_carton', $types);
    }
}
