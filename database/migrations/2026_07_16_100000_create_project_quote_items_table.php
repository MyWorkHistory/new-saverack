<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('line_type', 64);
            $table->string('name', 512);
            $table->decimal('quantity', 12, 4)->default(1);
            $table->integer('unit_price_cents')->default(0);
            $table->integer('line_total_cents')->default(0);
            $table->string('sku', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_quote_items');
    }
};
