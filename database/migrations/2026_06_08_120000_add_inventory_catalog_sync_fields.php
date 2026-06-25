<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shiphero_inventory_product_index', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('synced_at');
        });

        Schema::table('client_accounts', function (Blueprint $table) {
            $table->timestamp('inventory_catalog_synced_at')->nullable()->after('shiphero_client_refresh_token');
            $table->timestamp('inventory_catalog_sync_started_at')->nullable()->after('inventory_catalog_synced_at');
            $table->string('inventory_catalog_sync_status', 32)->default('idle')->after('inventory_catalog_sync_started_at');
            $table->unsignedInteger('inventory_catalog_product_count')->default(0)->after('inventory_catalog_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'inventory_catalog_synced_at',
                'inventory_catalog_sync_started_at',
                'inventory_catalog_sync_status',
                'inventory_catalog_product_count',
            ]);
        });

        Schema::table('shiphero_inventory_product_index', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
