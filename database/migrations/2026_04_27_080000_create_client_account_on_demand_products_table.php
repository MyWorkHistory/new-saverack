<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_account_on_demand_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('sku', 128);
            $table->string('name', 255);
            $table->string('category', 64);
            $table->unsignedBigInteger('price_cents');
            $table->timestamps();

            $table->unique(['client_account_id', 'sku'], 'ca_on_demand_products_account_sku_unique');
            $table->index(['category', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_account_on_demand_products');
    }
};
