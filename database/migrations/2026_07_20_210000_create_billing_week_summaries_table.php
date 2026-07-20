<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_week_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');
            $table->date('week_end');
            $table->bigInteger('total_billed_cents')->default(0);
            $table->bigInteger('fulfillment_cents')->default(0);
            $table->bigInteger('postage_cents')->default(0);
            $table->bigInteger('materials_cents')->default(0);
            $table->bigInteger('returns_cents')->default(0);
            $table->bigInteger('custom_work_cents')->default(0);
            $table->bigInteger('wholesale_cents')->default(0);
            $table->unsignedInteger('invoice_count')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('week_start');
            $table->index('week_end');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_week_summaries');
    }
};
