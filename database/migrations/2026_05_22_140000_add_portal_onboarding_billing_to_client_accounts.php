<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('client_accounts', 'onboarding_billing_method')) {
                $table->string('onboarding_billing_method', 32)->nullable()->after('default_payment_type');
            }
            if (! Schema::hasColumn('client_accounts', 'onboarding_billing_status')) {
                $table->string('onboarding_billing_status', 32)->nullable()->after('onboarding_billing_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('client_accounts', 'onboarding_billing_status')) {
                $table->dropColumn('onboarding_billing_status');
            }
            if (Schema::hasColumn('client_accounts', 'onboarding_billing_method')) {
                $table->dropColumn('onboarding_billing_method');
            }
        });
    }
};
