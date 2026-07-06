<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('category', 32);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('description')->nullable();
            $table->boolean('is_personal')->default(false);
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
            $table->index(['is_personal', 'created_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_calendar_events');
    }
};
