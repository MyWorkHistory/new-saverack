<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_packaging_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku', 64)->nullable()->unique();
            $table->string('category', 64);
            $table->string('type', 64);
            $table->unsignedInteger('cost_cents')->default(0);
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('on_hand')->default(0);
            $table->decimal('length', 10, 3)->nullable();
            $table->decimal('width', 10, 3)->nullable();
            $table->decimal('height', 10, 3)->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->index(['category', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_packaging_items');
    }
};
