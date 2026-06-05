<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_restock_beta_snapshots', function (Blueprint $table) {
            $table->json('completed_skus')->nullable()->after('rows');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_restock_beta_snapshots', function (Blueprint $table) {
            $table->dropColumn('completed_skus');
        });
    }
};
