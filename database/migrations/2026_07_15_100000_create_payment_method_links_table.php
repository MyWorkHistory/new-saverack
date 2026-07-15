<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentMethodLinksTable extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('method', 32); // credit_card | ach
            $table->string('replace_payment_method_id', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['client_account_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_links');
    }
}
