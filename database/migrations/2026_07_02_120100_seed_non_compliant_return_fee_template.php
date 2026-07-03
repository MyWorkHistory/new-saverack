<?php

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\PricingFeeTemplate;
use App\Services\PricingFeeTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pricing_fee_templates')) {
            return;
        }

        $template = PricingFeeTemplate::query()->firstOrCreate(
            [
                'name' => 'Non-Compliant Return',
                'category' => PricingFeeTemplate::CATEGORY_RETURNS,
            ],
            [
                'description' => 'Fee for non-compliant returns.',
                'amount' => '0.0000',
                'sort_order' => 7,
            ]
        );

        if (Schema::hasColumn('client_account_fees', 'pricing_template_id')) {
            ClientAccountFee::query()
                ->where('fee_group', PricingFeeTemplate::CATEGORY_RETURNS)
                ->where('line_code', ClientAccountFee::LINE_RETURNS_NON_COMPLIANT)
                ->whereNull('pricing_template_id')
                ->update(['pricing_template_id' => $template->id]);

            ClientAccountFee::query()
                ->where('pricing_template_id', $template->id)
                ->where(function ($q) {
                    $q->whereNull('label')->orWhere('label', '');
                })
                ->update(['label' => $template->name]);
        }

        /** @var PricingFeeTemplateService $provisioner */
        $provisioner = app(PricingFeeTemplateService::class);
        $provisioner->provisionTemplateToAllAccounts($template);

        ClientAccount::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($provisioner) {
                foreach ($accounts as $account) {
                    $provisioner->provisionAllTemplatesForAccount($account);
                }
            });
    }

    public function down(): void
    {
        // Templates and fees are not removed on rollback.
    }
};
