<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_account_returns', function (Blueprint $table) {
            $table->boolean('is_non_compliant')->default(false)->after('created_source');
            $table->string('non_compliant_reason', 64)->nullable()->after('is_non_compliant');
            $table->unsignedInteger('non_compliant_declared_items')->nullable()->after('non_compliant_reason');
            $table->decimal('return_fee_non_compliant', 12, 4)->nullable()->after('return_fee_additional_item');
        });
    }

    public function down(): void
    {
        Schema::table('client_account_returns', function (Blueprint $table) {
            $table->dropColumn([
                'is_non_compliant',
                'non_compliant_reason',
                'non_compliant_declared_items',
                'return_fee_non_compliant',
            ]);
        });
    }
};
