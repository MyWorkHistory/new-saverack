<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_order_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_order_id')->index();
            $table->string('type', 64);
            $table->string('title', 191);
            $table->text('detail')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_label', 128)->default('System');
            $table->timestamps();

            $table->foreign('shopify_order_id')
                ->references('id')
                ->on('shopify_orders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_order_activities');
    }
};
