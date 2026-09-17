<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_packaging_items', function (Blueprint $table) {
            $table->string('link_url', 2048)->nullable()->after('image_path');
        });

        Schema::create('shopify_packaging_location_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('shopify_packaging_item_id');
            $table->integer('available')->default(0);
            $table->timestamps();

            $table->unique(['location_id', 'shopify_packaging_item_id'], 'shopify_pkg_loc_unique');
            $table->foreign('location_id', 'shopify_pkg_loc_loc_fk')
                ->references('id')->on('shopify_warehouse_locations')->cascadeOnDelete();
            $table->foreign('shopify_packaging_item_id', 'shopify_pkg_loc_item_fk')
                ->references('id')->on('shopify_packaging_items')->cascadeOnDelete();
        });

        Schema::create('shopify_packaging_inventory_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_packaging_item_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('location_name')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 32);
            $table->string('type_label')->nullable();
            $table->integer('quantity_delta')->default(0);
            $table->integer('old_on_hand')->default(0);
            $table->integer('new_on_hand')->default(0);
            $table->text('note')->nullable();
            $table->string('transfer_group', 32)->nullable();
            $table->timestamps();

            $table->index(['shopify_packaging_item_id', 'created_at'], 'shopify_pkg_log_item_idx');
            $table->foreign('shopify_packaging_item_id', 'shopify_pkg_log_item_fk')
                ->references('id')->on('shopify_packaging_items')->cascadeOnDelete();
            $table->foreign('location_id', 'shopify_pkg_log_loc_fk')
                ->references('id')->on('shopify_warehouse_locations')->nullOnDelete();
            $table->foreign('user_id', 'shopify_pkg_log_user_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_packaging_inventory_logs');
        Schema::dropIfExists('shopify_packaging_location_items');
        Schema::table('shopify_packaging_items', function (Blueprint $table) {
            $table->dropColumn('link_url');
        });
    }
};
