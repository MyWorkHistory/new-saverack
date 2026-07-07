<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wholesale_orders', function (Blueprint $table) {
            $table->string('shipping_labels_provider', 32)->nullable()->after('shipping_method');
            $table->text('shipping_labels_comment')->nullable()->after('shipping_labels_provider');
            $table->string('shipping_label_path', 512)->nullable()->after('shipping_labels_comment');
            $table->string('shipping_label_original_name', 512)->nullable()->after('shipping_label_path');
            $table->string('shipping_label_mime', 128)->nullable()->after('shipping_label_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('wholesale_orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_labels_provider',
                'shipping_labels_comment',
                'shipping_label_path',
                'shipping_label_original_name',
                'shipping_label_mime',
            ]);
        });
    }
};
