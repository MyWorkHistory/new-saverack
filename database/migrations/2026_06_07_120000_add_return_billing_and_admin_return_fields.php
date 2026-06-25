<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bill_number')->unique();
            $table->string('status', 32)->default('open');
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->foreignId('client_account_return_id')->unique()->constrained('client_account_returns')->cascadeOnDelete();
            $table->date('bill_date');
            $table->bigInteger('total_cents')->default(0);
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_account_id', 'status']);
            $table->index('bill_date');
        });

        Schema::create('return_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_bill_id')->constrained('return_bills')->cascadeOnDelete();
            $table->string('line_type', 64);
            $table->string('name', 512);
            $table->decimal('quantity', 14, 4)->default(1);
            $table->bigInteger('unit_price_cents')->default(0);
            $table->bigInteger('line_total_cents')->default(0);
            $table->json('metadata')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['return_bill_id', 'sort_order']);
        });

        Schema::create('return_bill_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_bill_id')->constrained('return_bills')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255)->nullable();
            $table->string('event_type', 64);
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['return_bill_id', 'created_at']);
        });

        Schema::table('client_account_returns', function (Blueprint $table): void {
            $table->string('created_source', 16)->default('portal')->after('status');
            $table->decimal('return_fee_first_item', 12, 4)->nullable()->after('warehouse_private_note');
            $table->decimal('return_fee_additional_item', 12, 4)->nullable()->after('return_fee_first_item');
            $table->timestamp('fees_locked_at')->nullable()->after('return_fee_additional_item');
            $table->foreignId('return_bill_id')->nullable()->after('fees_locked_at')
                ->constrained('return_bills')->nullOnDelete();
            $table->foreignId('processed_by_user_id')->nullable()->after('processed_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('client_account_return_lines', function (Blueprint $table): void {
            $table->boolean('restock')->default(true)->after('return_reason');
        });
    }

    public function down(): void
    {
        Schema::table('client_account_return_lines', function (Blueprint $table): void {
            $table->dropColumn('restock');
        });

        Schema::table('client_account_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('processed_by_user_id');
            $table->dropConstrainedForeignId('return_bill_id');
            $table->dropColumn([
                'created_source',
                'return_fee_first_item',
                'return_fee_additional_item',
                'fees_locked_at',
            ]);
        });

        Schema::dropIfExists('return_bill_histories');
        Schema::dropIfExists('return_bill_items');
        Schema::dropIfExists('return_bills');
    }
};
