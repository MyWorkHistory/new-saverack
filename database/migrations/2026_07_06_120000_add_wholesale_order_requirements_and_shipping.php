<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wholesale_orders', function (Blueprint $table) {
            $table->string('shiphero_order_id', 255)->nullable()->after('instructions');
            $table->json('shipping_address')->nullable()->after('shiphero_order_id');
            $table->string('shipping_carrier', 128)->nullable()->after('shipping_address');
            $table->string('shipping_method', 128)->nullable()->after('shipping_carrier');
            $table->string('sku_barcode_labels', 32)->nullable()->after('shipping_method');
            $table->text('sku_barcode_labels_comment')->nullable()->after('sku_barcode_labels');
            $table->string('individual_sku_packaging', 32)->nullable()->after('sku_barcode_labels_comment');
            $table->text('individual_sku_packaging_comment')->nullable()->after('individual_sku_packaging');
            $table->string('bundle_configuration', 32)->nullable()->after('individual_sku_packaging_comment');
            $table->text('bundle_configuration_comment')->nullable()->after('bundle_configuration');
            $table->string('shipping_method_requirement', 32)->nullable()->after('bundle_configuration_comment');
        });

        Schema::table('wholesale_order_lines', function (Blueprint $table) {
            $table->string('status', 32)->default('pending')->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('wholesale_order_lines', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('wholesale_orders', function (Blueprint $table) {
            $table->dropColumn([
                'shiphero_order_id',
                'shipping_address',
                'shipping_carrier',
                'shipping_method',
                'sku_barcode_labels',
                'sku_barcode_labels_comment',
                'individual_sku_packaging',
                'individual_sku_packaging_comment',
                'bundle_configuration',
                'bundle_configuration_comment',
                'shipping_method_requirement',
            ]);
        });
    }
};
