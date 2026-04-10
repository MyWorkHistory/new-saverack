<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 64)->unique();
            $table->foreignId('client_account_id')->constrained('client_accounts')->restrictOnDelete();
            $table->string('status', 32)->index();
            $table->char('currency', 3)->default('USD');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->date('billing_period_start')->nullable();
            $table->date('billing_period_end')->nullable();
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('amount_paid_cents')->default(0);
            $table->unsignedBigInteger('balance_due_cents')->default(0);
            $table->unsignedSmallInteger('tax_rate_basis_points')->nullable()->comment('875 = 8.75%');
            $table->string('payment_terms', 64)->nullable();
            $table->string('po_number', 128)->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
