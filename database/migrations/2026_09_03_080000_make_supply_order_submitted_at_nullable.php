<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE supply_orders MODIFY submitted_at TIMESTAMP NULL DEFAULT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supply_orders MODIFY submitted_at TIMESTAMP NOT NULL');
    }
};
