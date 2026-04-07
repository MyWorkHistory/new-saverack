<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_user_role', 32)->nullable()->after('client_account_id');
            $table->boolean('is_account_primary')->default(false)->after('account_user_role');
            $table->index(['client_account_id', 'is_account_primary'], 'users_client_account_primary_lookup');
        });

        DB::table('users')
            ->whereNotNull('client_account_id')
            ->update([
                'account_user_role' => 'admin',
                'is_account_primary' => true,
            ]);

    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_client_account_primary_lookup');
            $table->dropColumn(['account_user_role', 'is_account_primary']);
        });
    }
};
