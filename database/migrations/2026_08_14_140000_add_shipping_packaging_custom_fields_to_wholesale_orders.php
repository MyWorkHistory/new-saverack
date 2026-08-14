<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wholesale_orders', function (Blueprint $table) {
            $table->string('shipping_packaging_qty_per_box', 64)->nullable()->after('shipping_method_requirement_comment');
            $table->string('shipping_packaging_box_size', 128)->nullable()->after('shipping_packaging_qty_per_box');
        });
    }

    public function down(): void
    {
        Schema::table('wholesale_orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_packaging_qty_per_box',
                'shipping_packaging_box_size',
            ]);
        });
    }
};
