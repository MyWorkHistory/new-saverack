<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInventoryProductCrmStatusTable extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_product_crm_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('sku', 255);
            $table->boolean('crm_active')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_account_id', 'sku'], 'inventory_product_crm_status_account_sku_unique');
            $table->index(['client_account_id', 'crm_active'], 'inventory_product_crm_status_account_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_product_crm_status');
    }
}
