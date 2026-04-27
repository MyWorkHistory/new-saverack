<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32)->default('pending');
            $table->string('company_name', 190);
            $table->string('contact_first_name', 100)->nullable();
            $table->string('contact_last_name', 100)->nullable();
            $table->string('email', 190);
            $table->boolean('notify_email')->default(false);
            $table->string('telegram_handle', 190)->nullable();
            $table->string('whatsapp_e164', 32)->nullable();
            $table->foreignId('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('email');
            $table->index('account_manager_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_accounts');
    }
};
