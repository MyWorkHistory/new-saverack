<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('shopify_products', function (Blueprint $table) {
            if (! Schema::hasColumn('shopify_products', 'crm_product_kind')) {
                $table->string('crm_product_kind', 32)->default('standard')->after('product_type');
            }
        });

        Schema::table('shopify_product_variants', function (Blueprint $table) {
            if (! Schema::hasColumn('shopify_product_variants', 'crm_image_path')) {
                $table->string('crm_image_path', 512)->nullable()->after('raw_json');
            }
            if (! Schema::hasColumn('shopify_product_variants', 'synced_image_url')) {
                $table->string('synced_image_url', 2048)->nullable()->after('crm_image_path');
            }
            if (! Schema::hasColumn('shopify_product_variants', 'barcode_label_path')) {
                $table->string('barcode_label_path', 512)->nullable()->after('synced_image_url');
            }
            if (! Schema::hasColumn('shopify_product_variants', 'barcode_label_payload')) {
                $table->string('barcode_label_payload', 255)->nullable()->after('barcode_label_path');
            }
            if (! Schema::hasColumn('shopify_product_variants', 'barcode_label_generated_at')) {
                $table->timestamp('barcode_label_generated_at')->nullable()->after('barcode_label_payload');
            }
        });

        if (! Schema::hasTable('shopify_variant_bundle_components')) {
            Schema::create('shopify_variant_bundle_components', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('parent_variant_id');
                $table->unsignedBigInteger('component_variant_id');
                $table->unsignedInteger('quantity')->default(1);
                $table->timestamps();

                $table->unique(
                    ['parent_variant_id', 'component_variant_id'],
                    'shopify_bundle_parent_component_unique'
                );
                $table->foreign('parent_variant_id', 'shopify_bundle_parent_fk')
                    ->references('id')->on('shopify_product_variants')->cascadeOnDelete();
                $table->foreign('component_variant_id', 'shopify_bundle_component_fk')
                    ->references('id')->on('shopify_product_variants')->cascadeOnDelete();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('shopify_variant_bundle_components');

        Schema::table('shopify_product_variants', function (Blueprint $table) {
            foreach (['barcode_label_generated_at', 'barcode_label_payload', 'barcode_label_path', 'synced_image_url', 'crm_image_path'] as $col) {
                if (Schema::hasColumn('shopify_product_variants', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('shopify_products', function (Blueprint $table) {
            if (Schema::hasColumn('shopify_products', 'crm_product_kind')) {
                $table->dropColumn('crm_product_kind');
            }
        });
    }
};
