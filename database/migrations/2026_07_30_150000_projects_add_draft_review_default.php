<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // New projects default to Draft (existing rows keep their status).
        DB::statement("ALTER TABLE projects MODIFY status VARCHAR(32) NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE projects MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending'");
    }
};
