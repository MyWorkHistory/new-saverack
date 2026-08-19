<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('type', 64)->nullable();
            $table->boolean('pickable')->default(true);
            $table->boolean('sellable')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique('name', 'shopify_wh_loc_name_unique');
            $table->index('type', 'shopify_wh_loc_type_idx');
        });

        Schema::create('shopify_warehouse_location_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('shopify_variant_id');
            $table->integer('available')->default(0);
            $table->timestamps();

            $table->foreign('location_id', 'shopify_wh_item_loc_fk')
                ->references('id')->on('shopify_warehouse_locations')->cascadeOnDelete();
            $table->foreign('shopify_variant_id', 'shopify_wh_item_var_fk')
                ->references('id')->on('shopify_product_variants')->cascadeOnDelete();
            $table->unique(['location_id', 'shopify_variant_id'], 'shopify_wh_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_warehouse_location_items');
        Schema::dropIfExists('shopify_warehouse_locations');
    }
};
