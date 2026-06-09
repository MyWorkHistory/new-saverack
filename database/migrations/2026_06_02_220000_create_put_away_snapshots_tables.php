<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('put_away_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_account_id');
            $table->string('warehouse_id', 64)->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('status', 16)->default('ok');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->unique('client_account_id');
            $table->foreign('client_account_id')->references('id')->on('client_accounts')->cascadeOnDelete();
        });

        Schema::create('put_away_snapshot_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('put_away_snapshot_id');
            $table->string('sku', 255);
            $table->string('name', 512)->default('');
            $table->string('barcode', 255)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->unsignedInteger('receiving_qty')->default(0);
            $table->unsignedInteger('pickable_qty')->default(0);
            $table->unsignedInteger('non_pickable_qty')->default(0);
            $table->unsignedInteger('on_hand')->default(0);
            $table->unsignedInteger('backorder')->default(0);
            $table->timestamps();

            $table->foreign('put_away_snapshot_id')->references('id')->on('put_away_snapshots')->cascadeOnDelete();
            $table->index(['put_away_snapshot_id', 'sku']);
            $table->index(['put_away_snapshot_id', 'name']);
            $table->index(['put_away_snapshot_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('put_away_snapshot_rows');
        Schema::dropIfExists('put_away_snapshots');
    }
};
