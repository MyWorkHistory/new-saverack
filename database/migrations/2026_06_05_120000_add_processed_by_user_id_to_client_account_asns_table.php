<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_account_asns', function (Blueprint $table) {
            $table->foreignId('processed_by_user_id')->nullable()->after('processed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_account_asns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('processed_by_user_id');
        });
    }
};
