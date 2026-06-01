<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_restock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('warehouse_id', 64);
            $table->timestamp('computed_at')->nullable();
            $table->json('rows')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('status', 16)->default('ok');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->unique('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_restock_snapshots');
    }
};
