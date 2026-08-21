<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_order_line_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesale_order_line_id')
                ->constrained('wholesale_order_lines')
                ->cascadeOnDelete();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('height', 10, 2)->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['wholesale_order_line_id', 'sort_order'], 'wo_line_boxes_line_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_order_line_boxes');
    }
};
