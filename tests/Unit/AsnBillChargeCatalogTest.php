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
            'fee_group' => ClientAccountFee::GROUP_CUSTOM_WORK,
            'line_code' => 'template_99',
            'label' => 'Custom Hourly Work',
            'amount' => '95.0000',
            'currency' => 'USD',
            'sort_order' => 0,
        ]);

        $options = AsnBillChargeCatalog::optionsForAccount($account->fresh(['feeItems.pricingTemplate']));
        $hourly = collect($options)->firstWhere('line_type', AsnBill::LINE_CUSTOM_HOURLY_WORK);

        $this->assertNotNull($hourly);
        $this->assertSame(9500, $hourly['default_unit_price_cents']);
    }
}
