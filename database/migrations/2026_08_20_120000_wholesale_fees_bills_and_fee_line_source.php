<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL may be using the composite unique index as the only index that
        // satisfies the wholesale_order_id foreign key — drop FK first, then unique.
        Schema::table('wholesale_order_fee_lines', function (Blueprint $table) {
            $table->dropForeign(['wholesale_order_id']);
        });

        Schema::table('wholesale_order_fee_lines', function (Blueprint $table) {
            $table->dropUnique(['wholesale_order_id', 'line_type']);
        });

        Schema::table('wholesale_order_fee_lines', function (Blueprint $table) {
            $table->string('source', 32)->default('wholesale')->after('line_type');
            $table->unsignedBigInteger('client_account_fee_id')->nullable()->after('source');
            $table->index(['wholesale_order_id', 'line_type']);
            $table->foreign('wholesale_order_id')
                ->references('id')
                ->on('wholesale_orders')
                ->cascadeOnDelete();
            $table->foreign('client_account_fee_id')
                ->references('id')
                ->on('client_account_fees')
                ->nullOnDelete();
        });

        Schema::create('wholesale_bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bill_number')->unique();
            $table->string('status', 32)->default('open');
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->foreignId('wholesale_order_id')->unique()->constrained('wholesale_orders')->cascadeOnDelete();
            $table->string('display_name', 255)->nullable();
            $table->date('bill_date');
            $table->bigInteger('total_cents')->default(0);
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_account_id', 'status']);
            $table->index('bill_date');
        });

        Schema::create('wholesale_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesale_bill_id')->constrained('wholesale_bills')->cascadeOnDelete();
            $table->string('line_type', 64);
            $table->string('source', 32)->default('wholesale');
            $table->unsignedBigInteger('client_account_fee_id')->nullable();
            $table->string('name', 512);
            $table->decimal('quantity', 14, 4)->default(1);
            $table->bigInteger('unit_price_cents')->default(0);
            $table->bigInteger('line_total_cents')->default(0);
            $table->json('metadata')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['wholesale_bill_id', 'sort_order']);
            $table->foreign('client_account_fee_id')
                ->references('id')
                ->on('client_account_fees')
                ->nullOnDelete();
        });

        Schema::create('wholesale_bill_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wholesale_bill_id')->constrained('wholesale_bills')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255)->nullable();
            $table->string('event_type', 64);
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['wholesale_bill_id', 'created_at']);
        });

        Schema::table('wholesale_orders', function (Blueprint $table) {
            $table->foreignId('wholesale_bill_id')->nullable()->after('id')
                ->constrained('wholesale_bills')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wholesale_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wholesale_bill_id');
        });

        Schema::dropIfExists('wholesale_bill_histories');
        Schema::dropIfExists('wholesale_bill_items');
        Schema::dropIfExists('wholesale_bills');

        Schema::table('wholesale_order_fee_lines', function (Blueprint $table) {
            $table->dropForeign(['client_account_fee_id']);
            $table->dropForeign(['wholesale_order_id']);
            $table->dropIndex(['wholesale_order_id', 'line_type']);
            $table->dropColumn(['source', 'client_account_fee_id']);
            $table->unique(['wholesale_order_id', 'line_type']);
            $table->foreign('wholesale_order_id')
                ->references('id')
                ->on('wholesale_orders')
                ->cascadeOnDelete();
        });
    }
};
