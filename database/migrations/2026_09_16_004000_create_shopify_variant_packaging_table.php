<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_variant_packaging', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_product_variant_id');
            $table->unsignedBigInteger('shopify_packaging_item_id');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(
                ['shopify_product_variant_id', 'shopify_packaging_item_id'],
                'shopify_variant_packaging_unique'
            );
            $table->foreign('shopify_product_variant_id')
                ->references('id')
                ->on('shopify_product_variants')
                ->cascadeOnDelete();
            $table->foreign('shopify_packaging_item_id')
                ->references('id')
                ->on('shopify_packaging_items')
                ->cascadeOnDelete();
        });

        $now = now();
        $rows = [];
        $variants = DB::table('shopify_product_variants')
            ->where(function ($query) {
                $query->whereNotNull('packaging_item_id')
                    ->orWhereNotNull('packaging_material_item_id');
            })
            ->get(['id', 'packaging_item_id', 'packaging_material_item_id']);

        foreach ($variants as $variant) {
            $sort = 0;
            $seen = [];
            foreach (['packaging_item_id', 'packaging_material_item_id'] as $column) {
                $itemId = $variant->{$column} ?? null;
                if ($itemId === null || isset($seen[(int) $itemId])) {
                    continue;
                }
                $seen[(int) $itemId] = true;
                $rows[] = [
                    'shopify_product_variant_id' => (int) $variant->id,
                    'shopify_packaging_item_id' => (int) $itemId,
                    'sort' => $sort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $sort++;
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('shopify_variant_packaging')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_variant_packaging');
    }
};
