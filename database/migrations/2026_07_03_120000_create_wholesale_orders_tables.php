<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('order_number', 128);
            $table->string('order_type', 32);
            $table->string('status', 32)->default('draft');
            $table->text('instructions')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_account_id', 'status']);
            $table->index(['order_number']);
            $table->index(['created_at']);
        });

        Schema::create('wholesale_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesale_order_id')->constrained('wholesale_orders')->cascadeOnDelete();
            $table->string('sku', 255);
            $table->string('name', 512);
            $table->string('image_url', 2048)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('barcode_mode', 32)->default('ship_as_is');
            $table->string('barcode_path', 512)->nullable();
            $table->string('barcode_original_name', 512)->nullable();
            $table->string('barcode_mime', 128)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['wholesale_order_id', 'sort_order']);
        });

        Schema::create('wholesale_order_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesale_order_id')->constrained('wholesale_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->string('attachment_path', 512)->nullable();
            $table->string('attachment_original_name', 512)->nullable();
            $table->string('attachment_mime', 128)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->timestamps();

            $table->index(['wholesale_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_order_comments');
        Schema::dropIfExists('wholesale_order_lines');
        Schema::dropIfExists('wholesale_orders');
    }
};
