<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shiphero_inventory_product_index', function (Blueprint $table) {
            $table->index(
                ['client_account_id', 'product_active', 'on_hand'],
                'shiphero_inv_idx_account_active_on_hand'
            );
            $table->index(
                ['client_account_id', 'last_seen_at'],
                'shiphero_inv_idx_account_last_seen'
            );
        });
    }

    public function down(): void
    {
        Schema::table('shiphero_inventory_product_index', function (Blueprint $table) {
            $table->dropIndex('shiphero_inv_idx_account_active_on_hand');
            $table->dropIndex('shiphero_inv_idx_account_last_seen');
        });
    }
};
