<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_stores')) {
            return;
        }

        DB::table('client_stores')->delete();
    }

    public function down(): void
    {
        // One-way data purge; legacy rows are not restored.
    }
};
