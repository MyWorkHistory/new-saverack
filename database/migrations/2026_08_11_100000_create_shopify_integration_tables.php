<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clean partial tables from a prior failed run (MySQL DDL is often non-transactional).
        $this->down();

        Schema::create('client_account_shopify_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_account_id')->unique();
            $table->string('shop_domain', 191);
            $table->text('admin_api_access_token')->nullable();
            $table->string('api_version', 32)->default('2025-01');
            $table->string('webhook_secret', 255)->nullable();
            $table->string('status', 32)->default('disconnected');
            $table->string('shop_name', 255)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_product_sync_at')->nullable();
            $table->timestamp('last_order_sync_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamps();

            $table->foreign('client_account_id', 'shopify_conn_account_fk')
                ->references('id')->on('client_accounts')->cascadeOnDelete();
            $table->index('shop_domain');
            $table->index('status');
        });

        Schema::create('shopify_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->string('shopify_location_id', 64);
            $table->string('name', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('legacy')->default(false);
            $table->json('address_json')->nullable();
            $table->timestamps();

            $table->foreign('connection_id', 'shopify_loc_conn_fk')
                ->references('id')->on('client_account_shopify_connections')->cascadeOnDelete();
            $table->unique(['connection_id', 'shopify_location_id'], 'shopify_locations_conn_loc_unique');
        });

        Schema::create('shopify_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->string('shopify_product_id', 64);
            $table->string('title', 500)->nullable();
            $table->string('handle', 255)->nullable();
            $table->string('status', 32)->nullable();
            $table->string('vendor', 255)->nullable();
            $table->string('product_type', 255)->nullable();
            $table->timestamp('crm_locked_at')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->foreign('connection_id', 'shopify_prod_conn_fk')
                ->references('id')->on('client_account_shopify_connections')->cascadeOnDelete();
            $table->unique(['connection_id', 'shopify_product_id'], 'shopify_products_conn_prod_unique');
            $table->index(['connection_id', 'status'], 'shopify_prod_conn_status_idx');
        });

        Schema::create('shopify_product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('shopify_product_id');
            $table->string('shopify_variant_id', 64);
            $table->string('shopify_inventory_item_id', 64)->nullable();
            $table->string('title', 500)->nullable();
            $table->string('sku', 255)->nullable();
            $table->string('barcode', 255)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('weight', 12, 4)->nullable();
            $table->string('weight_unit', 16)->nullable();
            $table->boolean('requires_shipping')->default(true);
            $table->timestamp('crm_locked_at')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->foreign('connection_id', 'shopify_var_conn_fk')
                ->references('id')->on('client_account_shopify_connections')->cascadeOnDelete();
            $table->foreign('shopify_product_id', 'shopify_var_prod_fk')
                ->references('id')->on('shopify_products')->cascadeOnDelete();
            $table->unique(['connection_id', 'shopify_variant_id'], 'shopify_variants_conn_var_unique');
            $table->index(['connection_id', 'shopify_inventory_item_id'], 'shopify_variants_conn_inv_idx');
            $table->index(['connection_id', 'sku'], 'shopify_var_conn_sku_idx');
        });

        Schema::create('shopify_inventory_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->string('shopify_inventory_item_id', 64);
            $table->string('shopify_location_id', 64);
            $table->integer('available')->default(0);
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamps();

            $table->foreign('connection_id', 'shopify_inv_conn_fk')
                ->references('id')->on('client_account_shopify_connections')->cascadeOnDelete();
            $table->unique(
                ['connection_id', 'shopify_inventory_item_id', 'shopify_location_id'],
                'shopify_inv_levels_unique'
            );
        });

        Schema::create('shopify_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->string('shopify_order_id', 64);
            $table->string('name', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('financial_status', 64)->nullable();
            $table->string('fulfillment_status', 64)->nullable();
            $table->string('currency', 16)->nullable();
            $table->decimal('total_price', 12, 2)->nullable();
            $table->timestamp('shopify_created_at')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('customer_json')->nullable();
            $table->json('shipping_address_json')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->foreign('connection_id', 'shopify_ord_conn_fk')
                ->references('id')->on('client_account_shopify_connections')->cascadeOnDelete();
            $table->unique(['connection_id', 'shopify_order_id'], 'shopify_orders_conn_ord_unique');
            $table->index(['connection_id', 'fulfillment_status'], 'shopify_ord_conn_ful_idx');
            $table->index(['connection_id', 'shopify_created_at'], 'shopify_ord_conn_created_idx');
        });

        Schema::create('shopify_order_line_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('shopify_order_id');
            $table->string('shopify_line_item_id', 64);
            $table->string('shopify_variant_id', 64)->nullable();
            $table->string('shopify_product_id', 64)->nullable();
            $table->string('sku', 255)->nullable();
            $table->string('title', 500)->nullable();
            $table->string('variant_title', 500)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('fulfillable_quantity')->default(0);
            $table->unsignedInteger('fulfilled_quantity')->default(0);
            $table->decimal('price', 12, 2)->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->foreign('connection_id', 'shopify_line_conn_fk')
                ->references('id')->on('client_account_shopify_connections')->cascadeOnDelete();
            $table->foreign('shopify_order_id', 'shopify_line_ord_fk')
                ->references('id')->on('shopify_orders')->cascadeOnDelete();
            $table->unique(['connection_id', 'shopify_line_item_id'], 'shopify_lines_conn_line_unique');
        });

        Schema::create('shopify_fulfillment_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('shopify_order_id');
            $table->string('shopify_fulfillment_order_id', 64);
            $table->string('status', 64)->nullable();
            $table->string('request_status', 64)->nullable();
            $table->string('shopify_location_id', 64)->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->foreign('connection_id', 'shopify_fo_conn_fk')
                ->references('id')->on('client_account_shopify_connections')->cascadeOnDelete();
            $table->foreign('shopify_order_id', 'shopify_fo_ord_fk')
                ->references('id')->on('shopify_orders')->cascadeOnDelete();
            $table->unique(['connection_id', 'shopify_fulfillment_order_id'], 'shopify_fos_conn_fo_unique');
        });

        Schema::create('shopify_fulfillment_order_line_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('shopify_fulfillment_order_id');
            $table->unsignedBigInteger('shopify_order_line_item_id')->nullable();
            $table->string('shopify_fo_line_item_id', 64);
            $table->string('shopify_line_item_id', 64)->nullable();
            $table->unsignedInteger('total_quantity')->default(0);
            $table->unsignedInteger('remaining_quantity')->default(0);
            $table->json('raw_json')->nullable();
            $table->timestamps();

            // Short FK names — MySQL identifier limit is 64 chars.
            $table->foreign('connection_id', 'shopify_fol_conn_fk')
                ->references('id')->on('client_account_shopify_connections')->cascadeOnDelete();
            $table->foreign('shopify_fulfillment_order_id', 'shopify_fol_fo_fk')
                ->references('id')->on('shopify_fulfillment_orders')->cascadeOnDelete();
            $table->foreign('shopify_order_line_item_id', 'shopify_fol_line_fk')
                ->references('id')->on('shopify_order_line_items')->nullOnDelete();
            $table->unique(['connection_id', 'shopify_fo_line_item_id'], 'shopify_fo_lines_unique');
        });

        Schema::create('shopify_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('shopify_order_id');
            $table->string('shopify_fulfillment_id', 64)->nullable();
            $table->string('status', 64)->nullable();
            $table->string('tracking_company', 128)->nullable();
            $table->string('tracking_number', 191)->nullable();
            $table->json('line_items_json')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->foreign('connection_id', 'shopify_ful_conn_fk')
                ->references('id')->on('client_account_shopify_connections')->cascadeOnDelete();
            $table->foreign('shopify_order_id', 'shopify_ful_ord_fk')
                ->references('id')->on('shopify_orders')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'shopify_ful_user_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['connection_id', 'shopify_order_id'], 'shopify_ful_conn_ord_idx');
        });

        Schema::create('shopify_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 191)->unique();
            $table->string('topic', 128);
            $table->string('shop_domain', 191)->nullable();
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_error', 500)->nullable();
            $table->timestamps();

            $table->foreign('connection_id', 'shopify_wh_conn_fk')
                ->references('id')->on('client_account_shopify_connections')->nullOnDelete();
            $table->index(['connection_id', 'processed_at'], 'shopify_wh_conn_proc_idx');
            $table->index('topic');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_webhook_events');
        Schema::dropIfExists('shopify_fulfillments');
        Schema::dropIfExists('shopify_fulfillment_order_line_items');
        Schema::dropIfExists('shopify_fulfillment_orders');
        Schema::dropIfExists('shopify_order_line_items');
        Schema::dropIfExists('shopify_orders');
        Schema::dropIfExists('shopify_inventory_levels');
        Schema::dropIfExists('shopify_product_variants');
        Schema::dropIfExists('shopify_products');
        Schema::dropIfExists('shopify_locations');
        Schema::dropIfExists('client_account_shopify_connections');
    }
};
