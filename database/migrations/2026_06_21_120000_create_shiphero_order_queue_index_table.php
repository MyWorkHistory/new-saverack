<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shiphero_order_queue_index', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('shiphero_order_id', 191);
            $table->string('queue_kind', 32);
            $table->string('hold_reason', 32)->nullable();
            $table->boolean('ready_to_ship')->default(false);
            $table->boolean('has_backorder')->default(false);
            $table->string('order_number', 128)->nullable();
            $table->string('order_number_search', 128)->nullable();
            $table->string('recipient_name', 255)->nullable();
            $table->timestamp('order_date')->nullable();
            $table->timestamp('ship_date')->nullable();
            $table->string('country', 64)->nullable();
            $table->string('display_status', 64)->nullable();
            $table->json('list_payload')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['client_account_id', 'shiphero_order_id', 'queue_kind'],
                'shiphero_order_queue_idx_account_order_kind'
            );
            $table->index(['client_account_id', 'queue_kind'], 'shiphero_order_queue_idx_account_kind');
            $table->index(['client_account_id', 'order_date'], 'shiphero_order_queue_idx_account_order_date');
            $table->index(['client_account_id', 'ship_date'], 'shiphero_order_queue_idx_account_ship_date');
            $table->index(['client_account_id', 'order_number_search'], 'shiphero_order_queue_idx_account_order_num');
            $table->index(['client_account_id', 'queue_kind', 'hold_reason'], 'shiphero_order_queue_idx_hold_reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shiphero_order_queue_index');
    }
};
