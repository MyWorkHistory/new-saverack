<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_warehouse_inventory_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_variant_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('location_name', 191);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 64);
            $table->string('type_label', 128);
            $table->integer('quantity_delta');
            $table->integer('old_on_hand');
            $table->integer('new_on_hand');
            $table->string('note', 500);
            $table->string('transfer_group', 64)->nullable();
            $table->timestamps();

            $table->foreign('shopify_variant_id', 'shopify_wh_log_var_fk')
                ->references('id')->on('shopify_product_variants')->cascadeOnDelete();
            $table->foreign('location_id', 'shopify_wh_log_loc_fk')
                ->references('id')->on('shopify_warehouse_locations')->nullOnDelete();
            $table->foreign('user_id', 'shopify_wh_log_user_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['shopify_variant_id', 'created_at'], 'shopify_wh_log_var_created_idx');
            $table->index('location_name', 'shopify_wh_log_loc_name_idx');
            $table->index('user_id', 'shopify_wh_log_user_idx');
            $table->index('type_label', 'shopify_wh_log_type_label_idx');
            $table->index('transfer_group', 'shopify_wh_log_trf_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_warehouse_inventory_logs');
    }
};
