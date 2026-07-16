<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_order_shipping_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesale_order_id')->constrained('wholesale_orders')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 128)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['wholesale_order_id', 'sort_order']);
        });

        Schema::create('wholesale_order_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesale_order_id')->constrained('wholesale_orders')->cascadeOnDelete();
            $table->string('package_type', 16); // box | pallet
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('height', 10, 2)->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['wholesale_order_id', 'package_type', 'sort_order']);
        });

        Schema::table('wholesale_orders', function (Blueprint $table) {
            $table->timestamp('boxes_saved_at')->nullable()->after('items_count');
            $table->timestamp('pallets_saved_at')->nullable()->after('boxes_saved_at');
        });

        Schema::table('wholesale_order_lines', function (Blueprint $table) {
            $table->decimal('weight', 12, 4)->nullable()->after('quantity_picked');
        });

        // Migrate legacy single shipping label into the new table when present.
        $orders = DB::table('wholesale_orders')
            ->whereNotNull('shipping_label_path')
            ->where('shipping_label_path', '!=', '')
            ->get(['id', 'shipping_label_path', 'shipping_label_original_name', 'shipping_label_mime']);

        foreach ($orders as $order) {
            $exists = DB::table('wholesale_order_shipping_labels')
                ->where('wholesale_order_id', $order->id)
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('wholesale_order_shipping_labels')->insert([
                'wholesale_order_id' => $order->id,
                'path' => $order->shipping_label_path,
                'original_name' => $order->shipping_label_original_name,
                'mime' => $order->shipping_label_mime,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('wholesale_order_lines', function (Blueprint $table) {
            $table->dropColumn('weight');
        });

        Schema::table('wholesale_orders', function (Blueprint $table) {
            $table->dropColumn(['boxes_saved_at', 'pallets_saved_at']);
        });

        Schema::dropIfExists('wholesale_order_packages');
        Schema::dropIfExists('wholesale_order_shipping_labels');
    }
};
