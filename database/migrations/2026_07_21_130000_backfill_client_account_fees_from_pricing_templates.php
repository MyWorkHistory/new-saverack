<?php

use App\Models\ClientAccountFee;
use App\Models\PricingFeeTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pricing_fee_templates') || ! Schema::hasTable('client_account_fees')) {
            return;
        }

        if (! Schema::hasColumn('client_account_fees', 'pricing_template_id')) {
            return;
        }

        PricingFeeTemplate::query()
            ->orderBy('id')
            ->chunkById(100, function ($templates) {
                foreach ($templates as $template) {
                    ClientAccountFee::query()
                        ->where('pricing_template_id', $template->id)
                        ->update([
                            'label' => $template->name,
                            'description' => $template->description,
                            'icon_path' => $template->icon_path,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible data backfill.
    }
};
