<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_customer_id')->nullable();
            $table->unique('legacy_customer_id');
        });

        Schema::table('client_stores', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_store_id')->nullable();
            $table->unique('legacy_store_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropUnique(['legacy_customer_id']);
            $table->dropColumn('legacy_customer_id');
        });

        Schema::table('client_stores', function (Blueprint $table) {
            $table->dropUnique(['legacy_store_id']);
            $table->dropColumn('legacy_store_id');
        });
    }
};
