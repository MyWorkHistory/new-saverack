<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repair migration: an earlier migration may be recorded in `migrations` without
 * this column existing on client_accounts (failed deploy, manual rollback, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('client_accounts', 'billing_available_funds_cents')) {
            return;
        }

        Schema::table('client_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('billing_available_funds_cents')->default(0);
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
