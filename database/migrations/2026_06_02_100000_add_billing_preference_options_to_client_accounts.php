<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBillingPreferenceOptionsToClientAccounts extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->string('postage_option', 64)
                ->default('save_rack_all_postage')
                ->after('default_payment_type');
            $table->string('packaging_option', 64)
                ->default('save_rack_all_packaging')
                ->after('postage_option');
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn(['postage_option', 'packaging_option']);
        });
    }
}
