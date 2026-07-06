<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wholesale_orders', function (Blueprint $table) {
            $table->string('cover_existing_barcodes', 32)->nullable()->after('sku_barcode_labels_comment');
            $table->text('cover_existing_barcodes_comment')->nullable()->after('cover_existing_barcodes');
            $table->text('shipping_method_requirement_comment')->nullable()->after('shipping_method_requirement');
            $table->string('master_cartons', 32)->nullable()->after('shipping_method_requirement_comment');
            $table->text('master_cartons_comment')->nullable()->after('master_cartons');
        });
    }

    public function down(): void
    {
        Schema::table('wholesale_orders', function (Blueprint $table) {
            $table->dropColumn([
                'cover_existing_barcodes',
                'cover_existing_barcodes_comment',
                'shipping_method_requirement_comment',
                'master_cartons',
                'master_cartons_comment',
            ]);
        });
    }
};
