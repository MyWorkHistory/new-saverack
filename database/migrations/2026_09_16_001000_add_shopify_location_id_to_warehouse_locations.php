<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_warehouse_locations', function (Blueprint $table) {
            $table->string('shopify_location_id', 64)->nullable()->after('name');
            $table->unique('shopify_location_id', 'shopify_wh_loc_shopify_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_warehouse_locations', function (Blueprint $table) {
            $table->dropUnique('shopify_wh_loc_shopify_id_unique');
            $table->dropColumn('shopify_location_id');
        });
    }
};
