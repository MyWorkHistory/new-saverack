<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_order_fee_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesale_order_id')->constrained('wholesale_orders')->cascadeOnDelete();
            $table->string('line_type', 64);
            $table->string('name', 255);
            $table->decimal('quantity', 12, 4);
            $table->unsignedInteger('unit_price_cents')->default(0);
            $table->timestamps();

            $table->unique(['wholesale_order_id', 'line_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_order_fee_lines');
    }
};
