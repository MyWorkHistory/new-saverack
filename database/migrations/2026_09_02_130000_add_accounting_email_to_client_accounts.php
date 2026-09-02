<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_accounts')) {
            return;
        }

        if (! Schema::hasColumn('client_accounts', 'accounting_email')) {
            Schema::table('client_accounts', function (Blueprint $table) {
                $table->string('accounting_email', 190)->nullable()->after('email');
            });
        }

        DB::table('client_accounts')
            ->whereNull('accounting_email')
            ->update(['accounting_email' => DB::raw('email')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_accounts')) {
            return;
        }

        if (Schema::hasColumn('client_accounts', 'accounting_email')) {
            Schema::table('client_accounts', function (Blueprint $table) {
                $table->dropColumn('accounting_email');
            });
        }
    }
};
