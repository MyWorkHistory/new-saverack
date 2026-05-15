<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WidenShipheroProductIdOnAsnLines extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_account_asn_lines')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE client_account_asn_lines MODIFY shiphero_product_id VARCHAR(191) NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_account_asn_lines')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE client_account_asn_lines MODIFY shiphero_product_id VARCHAR(64) NULL');
        }
    }
}
