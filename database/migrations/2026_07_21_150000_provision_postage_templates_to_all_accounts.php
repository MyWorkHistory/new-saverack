<?php

use App\Models\PricingFeeTemplate;
use App\Services\PricingFeeTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pricing_fee_templates') || ! Schema::hasTable('client_account_fees')) {
            return;
        }

        /** @var PricingFeeTemplateService $provisioner */
        $provisioner = app(PricingFeeTemplateService::class);

        PricingFeeTemplate::query()
            ->where('category', PricingFeeTemplate::CATEGORY_POSTAGE)
            ->orderBy('id')
            ->each(function (PricingFeeTemplate $template) use ($provisioner) {
                $provisioner->provisionTemplateToAllAccounts($template);
            });
    }

    public function down(): void
    {
        // Irreversible data backfill.
    }
};
