<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_fee_templates') && ! Schema::hasColumn('pricing_fee_templates', 'cost')) {
            Schema::table('pricing_fee_templates', function (Blueprint $table) {
                $table->decimal('cost', 12, 4)->nullable()->after('amount');
            });
        }

        if (Schema::hasTable('client_account_fees') && ! Schema::hasColumn('client_account_fees', 'cost')) {
            Schema::table('client_account_fees', function (Blueprint $table) {
                $table->decimal('cost', 12, 4)->nullable()->after('amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pricing_fee_templates') && Schema::hasColumn('pricing_fee_templates', 'cost')) {
            Schema::table('pricing_fee_templates', function (Blueprint $table) {
                $table->dropColumn('cost');
            });
        }

        if (Schema::hasTable('client_account_fees') && Schema::hasColumn('client_account_fees', 'cost')) {
            Schema::table('client_account_fees', function (Blueprint $table) {
                $table->dropColumn('cost');
            });
        }
    }
};
