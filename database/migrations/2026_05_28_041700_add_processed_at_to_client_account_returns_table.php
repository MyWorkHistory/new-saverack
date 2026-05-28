<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_account_returns', function (Blueprint $table): void {
            $table->timestamp('processed_at')->nullable()->after('warehouse_private_note');
        });
    }

    public function down(): void
    {
        Schema::table('client_account_returns', function (Blueprint $table): void {
            $table->dropColumn('processed_at');
        });
    }
};
