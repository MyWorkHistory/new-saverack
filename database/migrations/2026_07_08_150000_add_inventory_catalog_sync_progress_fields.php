<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->timestamp('inventory_catalog_sync_last_progress_at')->nullable()->after('inventory_catalog_sync_started_at');
            $table->unsignedInteger('inventory_catalog_sync_pages_completed')->default(0)->after('inventory_catalog_sync_last_progress_at');
            $table->string('inventory_catalog_sync_last_error', 500)->nullable()->after('inventory_catalog_sync_pages_completed');
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'inventory_catalog_sync_last_progress_at',
                'inventory_catalog_sync_pages_completed',
                'inventory_catalog_sync_last_error',
            ]);
        });
    }
};
