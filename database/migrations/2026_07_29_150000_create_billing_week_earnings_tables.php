<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_week_earnings', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');
            $table->date('week_end');
            $table->bigInteger('fulfillment_cents')->default(0);
            $table->bigInteger('postage_cents')->default(0);
            $table->bigInteger('materials_cents')->default(0);
            $table->bigInteger('returns_cents')->default(0);
            $table->bigInteger('custom_work_cents')->default(0);
            $table->bigInteger('wholesale_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->unsignedInteger('matched_line_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->string('status', 32)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('week_start');
            $table->index('week_end');
            $table->index('status');
        });

        Schema::create('billing_week_earning_unmatched_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_week_earning_id')
                ->constrained('billing_week_earnings')
                ->cascadeOnDelete();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->unsignedBigInteger('invoice_item_id')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('display_name', 512);
            $table->decimal('quantity', 16, 4)->default(0);
            $table->bigInteger('billed_cents')->default(0);
            $table->string('reason', 32);
            $table->timestamps();

            $table->index(['billing_week_earning_id', 'reason'], 'bwe_unmatched_earning_reason_idx');
            $table->index('client_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_week_earning_unmatched_items');
        Schema::dropIfExists('billing_week_earnings');
    }
};
