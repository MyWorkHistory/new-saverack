<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('packaging_item_id')->nullable()->after('height');
            $table->unsignedBigInteger('packaging_material_item_id')->nullable()->after('packaging_item_id');

            $table->foreign('packaging_item_id')
                ->references('id')
                ->on('shopify_packaging_items')
                ->nullOnDelete();
            $table->foreign('packaging_material_item_id')
                ->references('id')
                ->on('shopify_packaging_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->dropForeign(['packaging_item_id']);
            $table->dropForeign(['packaging_material_item_id']);
            $table->dropColumn(['packaging_item_id', 'packaging_material_item_id']);
        });
    }
};
