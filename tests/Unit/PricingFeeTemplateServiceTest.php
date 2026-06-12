<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\PricingFeeTemplate;
use App\Services\PricingFeeTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingFeeTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PricingFeeTemplateService
    {
        return $this->app->make(PricingFeeTemplateService::class);
    }

    private function account(): ClientAccount
    {
        return ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Pricing Test Co',
            'email' => 'pricing-test@example.test',
        ]);
    }

    public function test_new_template_provisions_row_on_existing_account(): void
    {
        $account = $this->account();

        $template = $this->service()->create([
            'name' => 'Inbound Handling',
            'description' => 'Per carton',
            'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
            'amount' => 2.5,
        ]);

        $fee = ClientAccountFee::query()
            ->where('client_account_id', $account->id)
            ->where('pricing_template_id', $template->id)
            ->first();

        $this->assertNotNull($fee);
        $this->assertSame(ClientAccountFee::GROUP_RECEIVING, $fee->fee_group);
        $this->assertSame('template_'.$template->id, $fee->line_code);
        $this->assertSame('Inbound Handling', $fee->label);
        $this->assertEquals('2.5000', (string) $fee->amount);
    }

    public function test_template_update_syncs_linked_account_fee_metadata_not_amount(): void
    {
        $account = $this->account();

        $template = $this->service()->create([
            'name' => 'Kitting',
            'category' => PricingFeeTemplate::CATEGORY_CUSTOM_WORK,
            'amount' => 1,
        ]);

        $feeId = ClientAccountFee::query()
            ->where('client_account_id', $account->id)
            ->where('pricing_template_id', $template->id)
            ->value('id');

        ClientAccountFee::query()->whereKey($feeId)->update(['amount' => '9.9900']);

        $this->service()->update($template, [
            'name' => 'Kitting (Updated)',
            'amount' => 4.25,
        ]);

        $fee = ClientAccountFee::query()->findOrFail($feeId);
        $this->assertSame('Kitting (Updated)', $fee->label);
        $this->assertEquals('9.9900', (string) $fee->amount);
    }

    public function test_provision_all_templates_on_account_create_path(): void
    {
        PricingFeeTemplate::query()->create([
            'name' => 'First Pick',
            'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
            'amount' => 1.25,
            'sort_order' => 0,
        ]);

        $account = $this->account();
        $this->service()->provisionAllTemplatesForAccount($account);

        $this->assertSame(
            1,
            ClientAccountFee::query()
                ->where('client_account_id', $account->id)
                ->whereNotNull('pricing_template_id')
                ->count()
        );
    }
}
