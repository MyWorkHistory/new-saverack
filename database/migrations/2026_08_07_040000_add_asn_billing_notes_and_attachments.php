<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->boolean('asn_billing_enabled')->default(false)->after('packaging_option');
        });

        Schema::create('client_account_asn_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_asn_id')->constrained('client_account_asns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['client_account_asn_id', 'created_at'], 'ca_asn_notes_asn_created_idx');
        });

        Schema::create('client_account_asn_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_asn_id')->constrained('client_account_asns')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime', 128)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->timestamps();

            $table->index(['client_account_asn_id', 'created_at'], 'ca_asn_attach_asn_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_account_asn_attachments');
        Schema::dropIfExists('client_account_asn_notes');

        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn('asn_billing_enabled');
        });
    }
};
