<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')->where('status', 'on_hold')->update(['status' => 'pending']);
    }

    public function down(): void
    {
        // Cannot restore which tasks were on hold vs originally pending.
    }
};
