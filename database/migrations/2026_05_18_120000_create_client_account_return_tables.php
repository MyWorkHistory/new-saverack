<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('client_account_return_lines');
        Schema::dropIfExists('client_account_returns');

        Schema::create('client_account_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('rma_number', 16);
            $table->string('status', 32)->default('draft');
            $table->string('return_type', 32)->default('direct');
            $table->string('shiphero_order_id', 64);
            $table->string('order_number', 128);
            $table->string('customer_name', 512)->default('');
            $table->unsignedInteger('items_count')->default(0);
            $table->text('warehouse_private_note')->nullable();
            $table->timestamps();

            $table->unique(['client_account_id', 'rma_number'], 'ca_returns_acct_rma_uq');
            $table->index(['client_account_id', 'status'], 'ca_returns_acct_status_idx');
            $table->index(['client_account_id', 'order_number'], 'ca_returns_acct_ordnum_idx');
        });

        Schema::create('client_account_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_return_id')->constrained('client_account_returns')->cascadeOnDelete();
            $table->string('shiphero_line_item_id', 64)->nullable();
            $table->string('sku', 255);
            $table->string('name', 512);
            $table->string('image_url', 2048)->nullable();
            $table->unsignedInteger('order_qty')->default(0);
            $table->unsignedInteger('return_qty')->default(0);
            $table->string('return_reason', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['client_account_return_id', 'sort_order'], 'ca_return_lines_ret_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_account_return_lines');
        Schema::dropIfExists('client_account_returns');
    }
};
