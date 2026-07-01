<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('order_number', 128);
            $table->string('status', 32)->default('draft');
            $table->json('shipping_address');
            $table->json('line_items')->nullable();
            $table->string('shipping_carrier', 200)->nullable();
            $table->string('shipping_method', 200)->nullable();
            $table->text('packing_note')->nullable();
            $table->text('gift_note')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('allow_partial')->default(false);
            $table->boolean('require_signature')->default(false);
            $table->string('shiphero_order_id', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_account_id', 'order_number', 'status']);
            $table->index(['status', 'client_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_drafts');
    }
};
