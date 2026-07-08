<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shiphero_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 191)->unique();
            $table->string('event_type', 128);
            $table->foreignId('client_account_id')->nullable()->constrained('client_accounts')->nullOnDelete();
            $table->string('shiphero_order_id', 191)->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_error', 500)->nullable();
            $table->timestamps();

            $table->index(['client_account_id', 'processed_at']);
            $table->index(['shiphero_order_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shiphero_webhook_events');
    }
};
