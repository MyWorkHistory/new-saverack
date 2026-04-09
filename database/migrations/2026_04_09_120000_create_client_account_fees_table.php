<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_account_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->string('fee_group', 32);
            $table->string('line_code', 64)->nullable();
            $table->string('label', 255)->nullable();
            $table->decimal('amount', 12, 4)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['client_account_id', 'fee_group']);
            $table->unique(
                ['client_account_id', 'fee_group', 'line_code'],
                'client_account_fees_account_group_line_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_account_fees');
    }
};
