<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('client_account_id')
            ->where('status', 'pending')
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        // Irreversible: pending portal users were activated intentionally.
    }
};
