<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('client_accounts', 'billing_available_funds_cents')) {
            return;
        }

        Schema::table('client_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('billing_available_funds_cents')
                ->default(0)
                ->after('cc_fee_percent');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('client_accounts', 'billing_available_funds_cents')) {
            return;
        }

        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn('billing_available_funds_cents');
        });
    }
};
