<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAdminBroadcastEmailsTable extends Migration
{
    public function up(): void
    {
        Schema::create('admin_broadcast_emails', function (Blueprint $table) {
            $table->id();
            $table->string('from_address', 255);
            $table->string('from_name', 255)->nullable();
            $table->string('subject', 500);
            $table->longText('body_html');
            $table->unsignedInteger('qty_sent')->default(0);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('status', 32)->default('sending');
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('sent_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_broadcast_emails');
    }
}
