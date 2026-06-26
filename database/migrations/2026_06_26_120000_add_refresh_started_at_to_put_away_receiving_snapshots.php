<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('put_away_receiving_snapshots', function (Blueprint $table) {
            $table->timestamp('refresh_started_at')->nullable()->after('computed_at');
        });
    }

    public function down(): void
    {
        Schema::table('put_away_receiving_snapshots', function (Blueprint $table) {
            $table->dropColumn('refresh_started_at');
        });
    }
};
