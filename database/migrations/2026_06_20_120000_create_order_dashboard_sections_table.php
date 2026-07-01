<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_dashboard_sections', function (Blueprint $table) {
            $table->string('section_key', 64)->primary();
            $table->json('payload')->nullable();
            $table->unsignedInteger('total_count')->default(0);
            $table->string('status', 16)->default('idle');
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamp('refresh_started_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_dashboard_sections');
    }
};
