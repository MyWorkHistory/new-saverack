<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_restock_beta_snapshots', function (Blueprint $table) {
            $table->json('sku_statuses')->nullable()->after('completed_skus');
        });

        // Migrate completed_skus → sku_statuses complete entries.
        $rows = DB::table('inventory_restock_beta_snapshots')->select('id', 'completed_skus', 'rows')->get();
        foreach ($rows as $row) {
            $statuses = [];
            $completed = json_decode((string) ($row->completed_skus ?? '[]'), true);
            if (! is_array($completed)) {
                $completed = [];
            }
            foreach ($completed as $sku) {
                $key = mb_strtolower(trim((string) $sku));
                if ($key !== '') {
                    $statuses[$key] = 'complete';
                }
            }

            $snapshotRows = json_decode((string) ($row->rows ?? '[]'), true);
            if (is_array($snapshotRows)) {
                foreach ($snapshotRows as $r) {
                    if (! is_array($r)) {
                        continue;
                    }
                    $key = mb_strtolower(trim((string) ($r['sku'] ?? '')));
                    if ($key === '' || isset($statuses[$key])) {
                        continue;
                    }
                    $statuses[$key] = 'pending';
                }
            }

            DB::table('inventory_restock_beta_snapshots')
                ->where('id', $row->id)
                ->update(['sku_statuses' => json_encode($statuses)]);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_restock_beta_snapshots', function (Blueprint $table) {
            $table->dropColumn('sku_statuses');
        });
    }
};
