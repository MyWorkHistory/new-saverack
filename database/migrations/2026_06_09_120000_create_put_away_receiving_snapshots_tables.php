<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('put_away_receiving_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('warehouse_id', 64);
            $table->timestamp('computed_at')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('status', 16)->default('ok');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('skipped_unresolved_account')->default(0);
            $table->timestamps();

            $table->unique('warehouse_id');
        });

        Schema::create('put_away_receiving_snapshot_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('put_away_receiving_snapshot_id');
            $table->unsignedBigInteger('client_account_id')->nullable();
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

            $table->foreign('put_away_receiving_snapshot_id', 'pa_recv_snap_rows_snap_fk')
                ->references('id')
                ->on('put_away_receiving_snapshots')
                ->cascadeOnDelete();
            $table->index(['put_away_receiving_snapshot_id', 'receiving_qty'], 'pa_recv_snap_rows_recv_idx');
            $table->index(['put_away_receiving_snapshot_id', 'client_account_id'], 'pa_recv_snap_rows_acct_idx');
            $table->index(['put_away_receiving_snapshot_id', 'sku'], 'pa_recv_snap_rows_sku_idx');
            $table->index(['put_away_receiving_snapshot_id', 'name'], 'pa_recv_snap_rows_name_idx');
            $table->index(['put_away_receiving_snapshot_id', 'barcode'], 'pa_recv_snap_rows_barcode_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('put_away_receiving_snapshot_rows');
        Schema::dropIfExists('put_away_receiving_snapshots');
    }
};
