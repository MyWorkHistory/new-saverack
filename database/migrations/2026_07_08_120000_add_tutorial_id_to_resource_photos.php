<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_photos', function (Blueprint $table) {
            $table->foreignId('tutorial_id')
                ->nullable()
                ->after('id')
                ->constrained('tutorials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('resource_photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tutorial_id');
        });
    }
};
