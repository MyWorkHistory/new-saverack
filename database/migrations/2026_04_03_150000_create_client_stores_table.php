<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('name', 190);
            $table->string('website', 512)->nullable();
            $table->string('marketplace', 190)->nullable();
            $table->timestamps();

            $table->index(['client_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_stores');
    }
};
