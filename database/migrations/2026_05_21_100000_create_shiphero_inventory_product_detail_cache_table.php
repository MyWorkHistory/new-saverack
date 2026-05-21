<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shiphero_inventory_product_detail_cache')) {
            return;
        }

        Schema::create('shiphero_inventory_product_detail_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_account_id');
            $table->foreign('client_account_id', 'sh_inv_detail_cache_acct_fk')
                ->references('id')
                ->on('client_accounts')
                ->cascadeOnDelete();
            $table->string('sku', 255);
            $table->string('sku_search', 255);
            $table->json('product_json')->nullable();
            $table->json('allocated_orders_json')->nullable();
            $table->json('backorder_orders_json')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['client_account_id', 'sku'], 'shiphero_inv_detail_cache_account_sku_unique');
            $table->index(['client_account_id', 'sku_search'], 'shiphero_inv_detail_cache_account_sku_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shiphero_inventory_product_detail_cache');
    }
};
