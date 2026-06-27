<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asn_bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bill_number')->unique();
            $table->string('status', 32)->default('open');
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->foreignId('client_account_asn_id')->unique()->constrained('client_account_asns')->cascadeOnDelete();
            $table->date('bill_date');
            $table->bigInteger('total_cents')->default(0);
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_account_id', 'status']);
            $table->index('bill_date');
        });

        Schema::create('asn_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asn_bill_id')->constrained('asn_bills')->cascadeOnDelete();
            $table->string('line_type', 64);
            $table->string('name', 512);
            $table->decimal('quantity', 14, 4)->default(1);
            $table->bigInteger('unit_price_cents')->default(0);
            $table->bigInteger('line_total_cents')->default(0);
            $table->json('metadata')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['asn_bill_id', 'sort_order']);
        });

        Schema::create('asn_bill_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asn_bill_id')->constrained('asn_bills')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255)->nullable();
            $table->string('event_type', 64);
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['asn_bill_id', 'created_at']);
        });

        Schema::table('client_account_asns', function (Blueprint $table) {
            $table->foreignId('asn_bill_id')->nullable()->after('custom_bill_id')
                ->constrained('asn_bills')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_account_asns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asn_bill_id');
        });

        Schema::dropIfExists('asn_bill_histories');
        Schema::dropIfExists('asn_bill_items');
        Schema::dropIfExists('asn_bills');
    }
};
