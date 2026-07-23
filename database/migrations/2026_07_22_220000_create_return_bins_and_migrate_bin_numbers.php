<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_bins', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('client_account_returns', function (Blueprint $table) {
            $table->foreignId('return_bin_id')
                ->nullable()
                ->after('return_bin_number')
                ->constrained('return_bins')
                ->nullOnDelete();
        });

        Schema::table('client_account_return_lines', function (Blueprint $table) {
            $table->foreignId('return_bin_id')
                ->nullable()
                ->after('return_bin_number')
                ->constrained('return_bins')
                ->nullOnDelete();
            $table->string('pick_location')->nullable()->after('return_bin_remaining_qty');
            $table->index(['return_bin_id', 'return_bin_remaining_qty'], 'ca_return_lines_bin_id_rem_idx');
        });

        $this->backfillNamedBins();
    }

    public function down(): void
    {
        Schema::table('client_account_return_lines', function (Blueprint $table) {
            $table->dropIndex('ca_return_lines_bin_id_rem_idx');
            $table->dropConstrainedForeignId('return_bin_id');
            $table->dropColumn('pick_location');
        });

        Schema::table('client_account_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_bin_id');
        });

        Schema::dropIfExists('return_bins');
    }

    private function backfillNamedBins(): void
    {
        $numbers = DB::table('client_account_returns')
            ->whereNotNull('return_bin_number')
            ->distinct()
            ->pluck('return_bin_number')
            ->merge(
                DB::table('client_account_return_lines')
                    ->whereNotNull('return_bin_number')
                    ->distinct()
                    ->pluck('return_bin_number')
            )
            ->map(fn ($n) => (int) $n)
            ->filter(fn (int $n) => $n > 0)
            ->unique()
            ->sort()
            ->values();

        $idByNumber = [];
        $now = now();

        foreach ($numbers as $number) {
            $name = 'Bin '.$number;
            $id = DB::table('return_bins')->insertGetId([
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $idByNumber[$number] = $id;
        }

        foreach ($idByNumber as $number => $binId) {
            DB::table('client_account_returns')
                ->where('return_bin_number', $number)
                ->update(['return_bin_id' => $binId]);

            DB::table('client_account_return_lines')
                ->where('return_bin_number', $number)
                ->update(['return_bin_id' => $binId]);
        }
    }
};
