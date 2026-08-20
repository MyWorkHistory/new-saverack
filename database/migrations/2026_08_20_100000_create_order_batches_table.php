<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 64);
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('completed_by_user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('batch_number', 'order_batches_number_unique');
            $table->index('status', 'order_batches_status_idx');
            $table->index('created_by_user_id', 'order_batches_created_by_idx');
            $table->index('completed_by_user_id', 'order_batches_completed_by_idx');

            $table->foreign('created_by_user_id', 'order_batches_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('completed_by_user_id', 'order_batches_completed_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_batches');
    }
};
