<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('client_accounts', 'onboarding_preferences')) {
                $table->json('onboarding_preferences')->nullable()->after('onboarding_billing_status');
            }
            if (! Schema::hasColumn('client_accounts', 'brand_logo_path')) {
                $table->string('brand_logo_path', 512)->nullable()->after('onboarding_preferences');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('client_accounts', 'brand_logo_path')) {
                $table->dropColumn('brand_logo_path');
            }
            if (Schema::hasColumn('client_accounts', 'onboarding_preferences')) {
                $table->dropColumn('onboarding_preferences');
            }
        });
    }
};
