<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_account_asns', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable()->after('date_received');
            $table->foreignId('custom_bill_id')->nullable()->after('warehouse_notes')
                ->constrained('custom_bills')->nullOnDelete();
            $table->decimal('non_compliant_fee', 12, 2)->nullable()->after('custom_bill_id');
        });

        Schema::table('client_account_asn_lines', function (Blueprint $table) {
            $table->string('line_status', 32)->default('pending')->after('rejected_qty');
            $table->string('barcode', 255)->nullable()->after('image_url');
            $table->decimal('weight', 12, 4)->nullable()->after('barcode');
            $table->decimal('length', 12, 4)->nullable()->after('weight');
            $table->decimal('width', 12, 4)->nullable()->after('length');
            $table->decimal('height', 12, 4)->nullable()->after('width');
            $table->timestamp('specs_cached_at')->nullable()->after('height');
        });

        $this->renumberDuplicateAsnNumbers();

        Schema::table('client_account_asns', function (Blueprint $table) {
            $table->dropUnique('ca_asns_acct_asnnum_uq');
            $table->unique('asn_number', 'ca_asns_asn_number_uq');
        });
    }

    public function down(): void
    {
        Schema::table('client_account_asns', function (Blueprint $table) {
            $table->dropUnique('ca_asns_asn_number_uq');
            $table->unique(['client_account_id', 'asn_number'], 'ca_asns_acct_asnnum_uq');
        });

        Schema::table('client_account_asn_lines', function (Blueprint $table) {
            $table->dropColumn([
                'line_status',
                'barcode',
                'weight',
                'length',
                'width',
                'height',
                'specs_cached_at',
            ]);
        });

        Schema::table('client_account_asns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custom_bill_id');
            $table->dropColumn(['processed_at', 'non_compliant_fee']);
        });
    }

    private function renumberDuplicateAsnNumbers(): void
    {
        $rows = DB::table('client_account_asns')
            ->orderBy('id')
            ->get(['id', 'asn_number']);

        $seen = [];
        $next = 1;
        foreach ($rows as $row) {
            $num = $this->parseAsnNumericSuffix((string) $row->asn_number);
            if ($num > $next - 1) {
                $next = $num + 1;
            }
        }

        foreach ($rows as $row) {
            $raw = trim((string) $row->asn_number);
            if ($raw === '' || $raw === 'TMP') {
                $new = str_pad((string) $next, 4, '0', STR_PAD_LEFT);
                DB::table('client_account_asns')->where('id', $row->id)->update(['asn_number' => $new]);
                $seen[$new] = true;
                $next++;

                continue;
            }
            if (isset($seen[$raw])) {
                $new = str_pad((string) $next, 4, '0', STR_PAD_LEFT);
                while (isset($seen[$new])) {
                    $next++;
                    $new = str_pad((string) $next, 4, '0', STR_PAD_LEFT);
                }
                DB::table('client_account_asns')->where('id', $row->id)->update(['asn_number' => $new]);
                $seen[$new] = true;
                $next++;
            } else {
                $seen[$raw] = true;
            }
        }
    }

    private function parseAsnNumericSuffix(string $raw): int
    {
        $s = trim($raw);
        if ($s === '' || $s === 'TMP') {
            return 0;
        }
        if (preg_match('/^(\d{1,4})$/', $s, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)$/', $s, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
};
