<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_calendar_events', function (Blueprint $table) {
            $table->string('repeat', 16)->default('none')->after('is_personal');
            $table->uuid('series_id')->nullable()->after('repeat');
            $table->index('series_id');
        });
    }

    public function down(): void
    {
        Schema::table('resource_calendar_events', function (Blueprint $table) {
            $table->dropIndex(['series_id']);
            $table->dropColumn(['repeat', 'series_id']);
        });
    }
};
