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

        $legacy = [
            [
                'name' => 'Returns Assembly',
                'description' => 'Fee for return assembly work.',
                'category' => PricingFeeTemplate::CATEGORY_RETURNS,
                'line_code' => ClientAccountFee::LINE_RETURNS_ASSEMBLY,
                'sort_order' => 4,
            ],
            [
                'name' => 'Returns Re-Packaging',
                'description' => 'Fee for return re-packaging.',
                'category' => PricingFeeTemplate::CATEGORY_RETURNS,
                'line_code' => ClientAccountFee::LINE_RETURNS_REPACKAGING,
                'sort_order' => 5,
            ],
            [
                'name' => 'Returns Disposal',
                'description' => 'Fee for return disposal.',
                'category' => PricingFeeTemplate::CATEGORY_RETURNS,
                'line_code' => ClientAccountFee::LINE_RETURNS_DISPOSAL,
                'sort_order' => 6,
            ],
        ];

        /** @var PricingFeeTemplateService $provisioner */
        $provisioner = app(PricingFeeTemplateService::class);

        foreach ($legacy as $row) {
            $lineCode = $row['line_code'];
            unset($row['line_code']);

            $template = PricingFeeTemplate::query()->firstOrCreate(
                [
                    'name' => $row['name'],
                    'category' => $row['category'],
                ],
                [
                    'description' => $row['description'],
                    'amount' => '0.0000',
                    'sort_order' => $row['sort_order'],
                ]
            );

            if (Schema::hasColumn('client_account_fees', 'pricing_template_id')) {
                ClientAccountFee::query()
                    ->where('fee_group', $row['category'])
                    ->where('line_code', $lineCode)
                    ->whereNull('pricing_template_id')
                    ->update(['pricing_template_id' => $template->id]);

                ClientAccountFee::query()
                    ->where('pricing_template_id', $template->id)
                    ->where(function ($q) {
                        $q->whereNull('label')->orWhere('label', '');
                    })
                    ->update(['label' => $template->name]);
            }

            $provisioner->provisionTemplateToAllAccounts($template);
        }

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
