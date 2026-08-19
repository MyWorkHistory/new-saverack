<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_account_shopify_connections', function (Blueprint $table) {
            $table->dropUnique(['client_account_id']);
            $table->index('client_account_id', 'shopify_conn_account_idx');
            $table->unique('shop_domain', 'shopify_conn_shop_domain_unique');
        });

        Schema::table('shopify_locations', function (Blueprint $table) {
            $table->boolean('import_orders')->default(true)->after('legacy');
            $table->boolean('sync_inventory')->default(true)->after('import_orders');
        });

        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->decimal('length', 12, 4)->nullable()->after('weight_unit');
            $table->decimal('width', 12, 4)->nullable()->after('length');
            $table->decimal('height', 12, 4)->nullable()->after('width');
            $table->string('dimension_unit', 16)->nullable()->after('height');
        });

        Schema::table('shopify_inventory_levels', function (Blueprint $table) {
            $table->timestamp('crm_set_at')->nullable()->after('available');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_inventory_levels', function (Blueprint $table) {
            $table->dropColumn('crm_set_at');
        });

        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->dropColumn(['length', 'width', 'height', 'dimension_unit']);
        });

        Schema::table('shopify_locations', function (Blueprint $table) {
            $table->dropColumn(['import_orders', 'sync_inventory']);
        });

        Schema::table('client_account_shopify_connections', function (Blueprint $table) {
            $table->dropUnique('shopify_conn_shop_domain_unique');
            $table->dropIndex('shopify_conn_account_idx');
            $table->unique('client_account_id');
        });
    }
};
