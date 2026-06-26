<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('put_away_receiving_snapshot_rows', function (Blueprint $table) {
            $table->unique(
                ['put_away_receiving_snapshot_id', 'client_account_id', 'sku'],
                'pa_recv_snap_rows_snap_acct_sku_uniq'
            );
        });
    }

    public function down(): void
    {
        Schema::table('put_away_receiving_snapshot_rows', function (Blueprint $table) {
            $table->dropUnique('pa_recv_snap_rows_snap_acct_sku_uniq');
        });
    }
};
