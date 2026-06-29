<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShipheroLegacyIdToClientAccountAsnLines extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_account_asn_lines')) {
            return;
        }

        Schema::table('client_account_asn_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('client_account_asn_lines', 'shiphero_legacy_id')) {
                $table->unsignedBigInteger('shiphero_legacy_id')->nullable()->after('shiphero_product_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_account_asn_lines')) {
            return;
        }

        Schema::table('client_account_asn_lines', function (Blueprint $table) {
            if (Schema::hasColumn('client_account_asn_lines', 'shiphero_legacy_id')) {
                $table->dropColumn('shiphero_legacy_id');
            }
        });
    }
}
