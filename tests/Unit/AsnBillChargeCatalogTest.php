<?php

namespace Tests\Unit;

use App\Models\AsnBill;
use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\PricingFeeTemplate;
use App\Support\Billing\AsnBillChargeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AsnBillChargeCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_unit_price_resolves_legacy_line_code(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Legacy Fee Co',
            'email' => 'legacy@example.test',
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RECEIVING,
            'line_code' => 'per_box',
            'label' => 'Receiving (Per Box)',
            'amount' => '5.0000',
            'currency' => 'USD',
            'sort_order' => 0,
        ]);

        $cents = AsnBillChargeCatalog::defaultUnitPriceCents(
            $account->fresh(['feeItems']),
            AsnBill::LINE_RECEIVING_PER_BOX
        );

        $this->assertSame(500, $cents);
    }

    public function test_default_unit_price_resolves_template_fee_by_label(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Template Fee Co',
            'email' => 'template@example.test',
        ]);

        $template = PricingFeeTemplate::query()->create([
            'name' => 'Receiving (Per Box)',
            'description' => 'Per box receiving',
            'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
            'amount' => '7.5000',
            'sort_order' => 1,
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'pricing_template_id' => $template->id,
            'fee_group' => ClientAccountFee::GROUP_RECEIVING,
            'line_code' => 'template_'.$template->id,
            'label' => 'Receiving (Per Box)',
            'amount' => '7.5000',
            'currency' => 'USD',
            'sort_order' => 1,
        ]);

        $cents = AsnBillChargeCatalog::defaultUnitPriceCents(
            $account->fresh(['feeItems.pricingTemplate']),
            AsnBill::LINE_RECEIVING_PER_BOX
        );

        $this->assertSame(750, $cents);
    }

    public function test_options_for_account_includes_template_prices(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Options Co',
            'email' => 'options@example.test',
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RECEIVING,
            'line_code' => 'template_99',
            'label' => 'Unit Count Verification (Per Hour)',
            'amount' => '95.0000',
            'currency' => 'USD',
            'sort_order' => 0,
        ]);

        $options = AsnBillChargeCatalog::optionsForAccount($account->fresh(['feeItems.pricingTemplate']));
        $hourly = collect($options)->firstWhere('qty_mode', AsnBillChargeCatalog::QTY_NONE);
        $this->assertNotNull($hourly);
        $this->assertSame('Unit Count Verification (Per Hour)', $hourly['display_name']);
        $this->assertSame(9500, $hourly['default_unit_price_cents']);
        $this->assertFalse($hourly['autofill']);
    }

    public function test_options_include_all_receiving_fees_and_sku_qty_mode(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Recv Fees Co',
            'email' => 'recv@example.test',
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RECEIVING,
            'line_code' => 'per_box',
            'label' => 'Receiving (Per Box)',
            'amount' => '2.5000',
            'currency' => 'USD',
            'sort_order' => 1,
        ]);
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RECEIVING,
            'line_code' => 'per_item',
            'label' => 'Receiving (Per SKU)',
            'amount' => '0.0100',
            'currency' => 'USD',
            'sort_order' => 2,
        ]);
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RECEIVING,
            'line_code' => 'per_container_20',
            'label' => 'Receiving (Per Container 20 ft)',
            'amount' => '250.0000',
            'currency' => 'USD',
            'sort_order' => 3,
        ]);

        $options = AsnBillChargeCatalog::optionsForAccount($account->fresh(['feeItems.pricingTemplate']));
        $this->assertCount(3, $options);
        $this->assertSame(AsnBillChargeCatalog::QTY_BOXES, $options[0]['qty_mode']);
        $this->assertSame(AsnBillChargeCatalog::QTY_SKU, $options[1]['qty_mode']);
        $this->assertSame(AsnBillChargeCatalog::QTY_NONE, $options[2]['qty_mode']);
        $this->assertSame(25000, $options[2]['default_unit_price_cents']);
    }
}
