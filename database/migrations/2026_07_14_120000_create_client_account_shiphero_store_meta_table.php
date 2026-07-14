<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientAccountShipheroStoreMetaTable extends Migration
{
    public function up(): void
    {
        Schema::create('client_account_shiphero_store_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('store_key', 191);
            $table->string('shop_id', 64)->nullable();
            $table->string('store_type', 32)->nullable();
            $table->timestamps();

            $table->unique(
                ['client_account_id', 'store_key'],
                'client_account_shiphero_store_meta_account_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_account_shiphero_store_meta');
    }
}
