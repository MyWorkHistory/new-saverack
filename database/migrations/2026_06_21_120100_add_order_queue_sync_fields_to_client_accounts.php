<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->timestamp('order_queue_synced_at')->nullable()->after('inventory_catalog_product_count');
            $table->timestamp('order_queue_sync_started_at')->nullable()->after('order_queue_synced_at');
            $table->string('order_queue_sync_status', 32)->default('idle')->after('order_queue_sync_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'order_queue_synced_at',
                'order_queue_sync_started_at',
                'order_queue_sync_status',
            ]);
        });
    }
};
