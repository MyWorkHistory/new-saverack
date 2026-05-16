<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shiphero_inventory_product_index', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->nullable()->constrained('client_accounts')->nullOnDelete();
            $table->string('shiphero_customer_account_id', 128)->nullable();
            $table->string('shiphero_product_id', 191)->nullable();
            $table->string('sku', 255);
            $table->string('sku_search', 255)->index();
            $table->string('name', 512)->nullable();
            $table->string('name_search', 512)->nullable();
            $table->string('barcode', 255)->nullable();
            $table->string('barcode_search', 255)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->boolean('product_active')->default(true);
            $table->boolean('kit')->default(false);
            $table->boolean('kit_build')->default(false);
            $table->string('warehouse_id', 255)->nullable();
            $table->boolean('warehouse_active')->default(true);
            $table->decimal('on_hand', 14, 4)->default(0);
            $table->decimal('allocated', 14, 4)->default(0);
            $table->decimal('backorder', 14, 4)->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['client_account_id', 'shiphero_customer_account_id', 'sku', 'warehouse_id'],
                'shiphero_inv_idx_account_sku_wh_unique'
            );
            $table->index(['client_account_id', 'on_hand'], 'shiphero_inv_idx_account_on_hand');
            $table->index(['client_account_id', 'backorder'], 'shiphero_inv_idx_account_backorder');
            $table->index(['client_account_id', 'kit', 'kit_build'], 'shiphero_inv_idx_account_kit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shiphero_inventory_product_index');
    }
};
