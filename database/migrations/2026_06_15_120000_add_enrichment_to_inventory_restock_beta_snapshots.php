<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_restock_beta_snapshots', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_restock_beta_snapshots', 'enrichment_status')) {
                $table->string('enrichment_status', 32)->default('completed')->after('completed_skus');
            }
            if (! Schema::hasColumn('inventory_restock_beta_snapshots', 'enrichment_error')) {
                $table->text('enrichment_error')->nullable()->after('enrichment_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_restock_beta_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_restock_beta_snapshots', 'enrichment_error')) {
                $table->dropColumn('enrichment_error');
            }
            if (Schema::hasColumn('inventory_restock_beta_snapshots', 'enrichment_status')) {
                $table->dropColumn('enrichment_status');
            }
        });
    }
};
