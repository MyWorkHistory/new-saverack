<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('status');
        });

        DB::table('client_accounts')
            ->where('status', 'paused')
            ->whereNull('paused_at')
            ->update(['paused_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};
