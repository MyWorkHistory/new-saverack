<?php

use App\Models\ClientAccountFee;
use App\Models\Permission;
use App\Models\PricingFeeTemplate;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defs = [
            ['key' => 'settings.view', 'label' => 'View settings', 'module' => 'settings'],
            ['key' => 'settings.update', 'label' => 'Update settings', 'module' => 'settings'],
        ];

        foreach ($defs as $p) {
            Permission::query()->firstOrCreate(
                ['key' => $p['key']],
                ['label' => $p['label'], 'module' => $p['module']]
            );
        }

        $admin = Role::query()->where('name', 'admin')->first();
        if ($admin !== null) {
            $admin->permissions()->sync(Permission::query()->pluck('id'));
        }

        if (! Schema::hasTable('pricing_fee_templates')) {
            return;
        }

        $legacy = [
            [
                'name' => 'First Pick',
                'description' => 'Fee for the first pick on an order.',
                'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
                'line_code' => ClientAccountFee::LINE_FIRST_PICK,
                'sort_order' => 0,
            ],
            [
                'name' => 'Additional Picks',
                'description' => 'Fee for each additional pick after the first.',
                'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
                'line_code' => ClientAccountFee::LINE_ADDITIONAL_PICKS,
                'sort_order' => 1,
            ],
            [
                'name' => 'Returns Processing',
                'description' => 'Base fee for processing a return.',
                'category' => PricingFeeTemplate::CATEGORY_RETURNS,
                'line_code' => ClientAccountFee::LINE_RETURNS_PROCESSING,
                'sort_order' => 2,
            ],
            [
                'name' => 'Returns Additional Items',
                'description' => 'Fee for each additional item on a return.',
                'category' => PricingFeeTemplate::CATEGORY_RETURNS,
                'line_code' => ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS,
                'sort_order' => 3,
            ],
        ];

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
        }
    }

    public function down(): void
    {
        // Permissions are not removed on rollback (same as billing permissions migration).
    }
};
