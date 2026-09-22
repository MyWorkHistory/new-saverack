<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_connection_shipping_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('shopify_packaging_item_id');
            $table->string('shopify_shipping_package_id', 191);
            $table->timestamps();

            $table->unique(
                ['connection_id', 'shopify_packaging_item_id'],
                'shopify_conn_pkg_item_unique'
            );
            $table->index('shopify_shipping_package_id', 'shopify_conn_pkg_gid_idx');

            $table->foreign('connection_id', 'shopify_conn_pkg_conn_fk')
                ->references('id')
                ->on('client_account_shopify_connections')
                ->cascadeOnDelete();
            $table->foreign('shopify_packaging_item_id', 'shopify_conn_pkg_item_fk')
                ->references('id')
                ->on('shopify_packaging_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_connection_shipping_packages');
    }
};
