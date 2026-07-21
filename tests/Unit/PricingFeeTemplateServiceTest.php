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

    public function test_postage_template_provisions_account_fees(): void
    {
        $account = $this->account();

        $template = $this->service()->create([
            'name' => 'USPS',
            'description' => 'USPS postage fee',
            'category' => PricingFeeTemplate::CATEGORY_POSTAGE,
            'amount' => 0.25,
        ]);

        $this->assertSame(PricingFeeTemplate::CATEGORY_POSTAGE, $template->category);
        $this->assertEquals('0.2500', (string) $template->amount);

        $fee = ClientAccountFee::query()
            ->where('client_account_id', $account->id)
            ->where('pricing_template_id', $template->id)
            ->first();

        $this->assertNotNull($fee);
        $this->assertSame(PricingFeeTemplate::CATEGORY_POSTAGE, $fee->fee_group);
        $this->assertSame('USPS', $fee->label);
        $this->assertEquals('0.2500', (string) $fee->amount);
    }

    public function test_provision_all_templates_includes_postage(): void
    {
        PricingFeeTemplate::query()->create([
            'name' => 'First Pick',
            'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
            'amount' => 1.25,
            'sort_order' => 0,
        ]);
        PricingFeeTemplate::query()->create([
            'name' => 'USPS',
            'category' => PricingFeeTemplate::CATEGORY_POSTAGE,
            'amount' => 0.25,
            'sort_order' => 1,
        ]);

        $account = $this->account();
        $this->service()->provisionAllTemplatesForAccount($account);

        $this->assertSame(
            2,
            ClientAccountFee::query()
                ->where('client_account_id', $account->id)
                ->whereNotNull('pricing_template_id')
                ->count()
        );
        $this->assertSame(
            1,
            ClientAccountFee::query()
                ->where('client_account_id', $account->id)
                ->where('fee_group', PricingFeeTemplate::CATEGORY_POSTAGE)
                ->count()
        );
    }

    public function test_updating_template_to_postage_keeps_linked_account_fees(): void
    {
        $account = $this->account();

        $template = $this->service()->create([
            'name' => 'Was Receiving',
            'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
            'amount' => 3,
        ]);

        $this->assertSame(
            1,
            ClientAccountFee::query()
                ->where('client_account_id', $account->id)
                ->where('pricing_template_id', $template->id)
                ->count()
        );

        $this->service()->update($template, [
            'category' => PricingFeeTemplate::CATEGORY_POSTAGE,
            'amount' => 0.25,
        ]);

        $fee = ClientAccountFee::query()
            ->where('pricing_template_id', $template->id)
            ->first();

        $this->assertNotNull($fee);
        $this->assertSame(PricingFeeTemplate::CATEGORY_POSTAGE, $fee->fee_group);
        $this->assertSame('Was Receiving', $fee->label);
        $this->assertEquals('3.0000', (string) $fee->amount);
    }

    public function test_template_cost_is_inherited_and_account_override_is_preserved(): void
    {
        $account = $this->account();

        $template = $this->service()->create([
            'name' => 'Hourly Labor',
            'category' => PricingFeeTemplate::CATEGORY_CUSTOM_WORK,
            'amount' => 45,
            'cost' => 18,
        ]);

        $fee = ClientAccountFee::query()
            ->where('client_account_id', $account->id)
            ->where('pricing_template_id', $template->id)
            ->first();

        $this->assertNotNull($fee);
        $this->assertNull($fee->cost);

        $payload = app(\App\Services\ClientAccountService::class)
            ->feesPayloadForApi($account->fresh(['feeItems.pricingTemplate']), true);
        $item = collect($payload['items'])->firstWhere('id', $fee->id);
        $this->assertNotNull($item);
        $this->assertSame(18.0, $item['cost']);
        $this->assertSame(18.0, $item['default_cost']);
        $this->assertFalse($item['cost_is_override']);

        $fee->update(['cost' => '22.0000']);

        $this->service()->update($template, [
            'cost' => 30,
        ]);

        $fee->refresh();
        $this->assertSame('22.0000', (string) $fee->cost);
        $this->assertSame('30.0000', (string) $template->fresh()->cost);

        $payloadAfter = app(\App\Services\ClientAccountService::class)
            ->feesPayloadForApi($account->fresh(['feeItems.pricingTemplate']), true);
        $itemAfter = collect($payloadAfter['items'])->firstWhere('id', $fee->id);
        $this->assertSame(22.0, $itemAfter['cost']);
        $this->assertSame(30.0, $itemAfter['default_cost']);
        $this->assertTrue($itemAfter['cost_is_override']);
    }
}
