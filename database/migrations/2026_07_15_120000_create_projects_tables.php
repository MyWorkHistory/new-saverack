<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_bills', function (Blueprint $table) {
            $table->string('name', 255)->nullable()->after('bill_number');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('pid', 32)->unique();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('custom_bill_id')->nullable()->constrained('custom_bills')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('client_account_id');
        });

        Schema::create('project_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_notes');
        Schema::dropIfExists('projects');

        Schema::table('custom_bills', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
