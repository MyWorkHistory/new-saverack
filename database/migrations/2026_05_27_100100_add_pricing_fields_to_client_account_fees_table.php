<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_account_fees', function (Blueprint $table) {
            $table->foreignId('pricing_template_id')
                ->nullable()
                ->after('client_account_id')
                ->constrained('pricing_fee_templates')
                ->nullOnDelete();
            $table->text('description')->nullable()->after('label');
            $table->string('icon_path', 512)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('client_account_fees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pricing_template_id');
            $table->dropColumn(['description', 'icon_path']);
        });
    }
};
