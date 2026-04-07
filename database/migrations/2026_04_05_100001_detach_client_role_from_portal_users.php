<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $clientRoleId = DB::table('roles')->where('name', 'client')->value('id');
        if ($clientRoleId === null) {
            return;
        }

        DB::table('role_user')
            ->where('role_id', $clientRoleId)
            ->whereIn('user_id', function ($q) {
                $q->select('id')->from('users')->whereNotNull('client_account_id');
            })
            ->delete();
    }

    public function down(): void
    {
    }
};
