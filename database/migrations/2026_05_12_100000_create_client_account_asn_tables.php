<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_account_asns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('asn_number', 64);
            $table->string('status', 32)->default('pending');
            $table->date('date_received')->nullable();
            $table->unsignedInteger('total_boxes')->default(0);
            $table->unsignedInteger('total_pallets')->default(0);
            $table->unsignedInteger('expected_qty')->default(0);
            $table->unsignedInteger('accepted_qty')->default(0);
            $table->unsignedInteger('rejected_qty')->default(0);
            $table->text('warehouse_notes')->nullable();
            $table->timestamps();

            $table->unique(['client_account_id', 'asn_number'], 'ca_asns_acct_asnnum_uq');
            $table->index(['client_account_id', 'status'], 'ca_asns_acct_status_idx');
        });

        Schema::create('client_account_asn_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_asn_id')->constrained('client_account_asns')->cascadeOnDelete();
            $table->string('shiphero_product_id', 64)->nullable();
            $table->string('sku', 255);
            $table->string('name', 512);
            $table->unsignedInteger('expected_qty')->default(0);
            $table->unsignedInteger('accepted_qty')->default(0);
            $table->unsignedInteger('rejected_qty')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['client_account_asn_id', 'sort_order'], 'ca_asn_lines_asn_sort_idx');
        });

        Schema::create('client_account_asn_trackings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_asn_id')->constrained('client_account_asns')->cascadeOnDelete();
            $table->string('carrier', 128)->default('');
            $table->string('tracking_number', 255)->default('');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['client_account_asn_id', 'sort_order']);
        });

        Schema::create('client_account_asn_vendor_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_asn_id')->constrained('client_account_asns')->cascadeOnDelete();
            $table->string('label', 512);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['client_account_asn_id', 'sort_order'], 'ca_asn_vnd_asn_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_account_asn_vendor_lines');
        Schema::dropIfExists('client_account_asn_trackings');
        Schema::dropIfExists('client_account_asn_lines');
        Schema::dropIfExists('client_account_asns');
    }
};
