<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('description');
            $table->string('sku', 128)->nullable();
            $table->string('service_code', 128)->nullable();
            $table->decimal('quantity', 14, 4)->default(1);
            $table->string('unit', 32)->nullable();
            $table->unsignedBigInteger('unit_price_cents')->default(0);
            $table->unsignedBigInteger('line_total_cents')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
