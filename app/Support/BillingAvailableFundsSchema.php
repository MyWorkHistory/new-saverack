<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class BillingAvailableFundsSchema
{
    public static function columnExists(): bool
    {
        return Schema::hasColumn('client_accounts', 'billing_available_funds_cents');
    }

    /**
     * Add billing_available_funds_cents when migration was not run yet (fail-safe for production).
     */
    public static function ensureColumn(): void
    {
        if (self::columnExists()) {
            return;
        }

        Schema::table('client_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('billing_available_funds_cents')->default(0);
        });
    }
}
