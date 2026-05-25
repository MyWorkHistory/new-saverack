<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shiphero_inventory_product_detail_cache', function (Blueprint $table) {
            if (! Schema::hasColumn('shiphero_inventory_product_detail_cache', 'parent_kits_json')) {
                $table->json('parent_kits_json')->nullable()->after('product_json');
            }
            if (! Schema::hasColumn('shiphero_inventory_product_detail_cache', 'kit_components_json')) {
                $table->json('kit_components_json')->nullable()->after('parent_kits_json');
            }
            if (! Schema::hasColumn('shiphero_inventory_product_detail_cache', 'product_synced_at')) {
                $table->timestamp('product_synced_at')->nullable()->after('backorder_orders_json');
            }
            if (! Schema::hasColumn('shiphero_inventory_product_detail_cache', 'parent_kits_synced_at')) {
                $table->timestamp('parent_kits_synced_at')->nullable()->after('product_synced_at');
            }
            if (! Schema::hasColumn('shiphero_inventory_product_detail_cache', 'kit_components_synced_at')) {
                $table->timestamp('kit_components_synced_at')->nullable()->after('parent_kits_synced_at');
            }
            if (! Schema::hasColumn('shiphero_inventory_product_detail_cache', 'allocated_orders_synced_at')) {
                $table->timestamp('allocated_orders_synced_at')->nullable()->after('kit_components_synced_at');
            }
            if (! Schema::hasColumn('shiphero_inventory_product_detail_cache', 'backorder_orders_synced_at')) {
                $table->timestamp('backorder_orders_synced_at')->nullable()->after('allocated_orders_synced_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shiphero_inventory_product_detail_cache', function (Blueprint $table) {
            foreach ([
                'backorder_orders_synced_at',
                'allocated_orders_synced_at',
                'kit_components_synced_at',
                'parent_kits_synced_at',
                'product_synced_at',
                'kit_components_json',
                'parent_kits_json',
            ] as $column) {
                if (Schema::hasColumn('shiphero_inventory_product_detail_cache', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
