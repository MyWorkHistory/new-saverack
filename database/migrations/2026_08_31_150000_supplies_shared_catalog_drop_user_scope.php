<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplies catalog is team-wide. Drop accidental per-user scoping if it was added manually.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplies') || ! Schema::hasColumn('supplies', 'user_id')) {
            return;
        }

        Schema::table('supplies', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('supplies', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('supplies') || Schema::hasColumn('supplies', 'user_id')) {
            return;
        }

        Schema::table('supplies', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });
    }
};
