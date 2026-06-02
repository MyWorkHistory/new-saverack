<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_restock_snapshots', function (Blueprint $table) {
            $table->timestamp('refresh_started_at')->nullable()->after('duration_ms');
            $table->unsignedInteger('progress_page')->nullable()->after('refresh_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_restock_snapshots', function (Blueprint $table) {
            $table->dropColumn(['refresh_started_at', 'progress_page']);
        });
    }
};
