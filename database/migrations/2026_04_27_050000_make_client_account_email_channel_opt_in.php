<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('client_accounts', 'notify_email')) {
            DB::table('client_accounts')->where('notify_email', true)->update(['notify_email' => false]);
            DB::statement('ALTER TABLE client_accounts MODIFY notify_email TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('client_accounts', 'notify_email')) {
            DB::statement('ALTER TABLE client_accounts MODIFY notify_email TINYINT(1) NOT NULL DEFAULT 1');
        }
    }
};
