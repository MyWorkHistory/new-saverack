<?php

use App\Support\Billing\InvoiceHistoryEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('invoice_number');
            $table->timestamp('share_token_generated_at')->nullable()->after('share_token');
            $table->bigInteger('manual_total_override_cents')->nullable()->after('balance_due_cents');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('category', 32)->default('other')->index()->after('invoice_id');
            $table->string('subtype', 64)->nullable()->after('category');
            $table->string('group_key', 255)->nullable()->index()->after('subtype');
            $table->string('display_name', 512)->nullable()->after('description');
        });

        $this->backfillInvoiceItemDisplayNames();

        Schema::table('invoice_histories', function (Blueprint $table) {
            $table->string('event_type', 32)->nullable()->index()->after('invoice_id');
            $table->text('message')->nullable()->after('action');
            $table->string('actor_name', 191)->nullable()->after('user_id');
        });

        $this->backfillInvoiceHistoryEventTypes();

        Schema::create('invoice_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('import_type', 32);
            $table->string('original_filename', 255)->nullable();
            $table->unsignedInteger('rows_processed')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->json('result_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_imports');

        Schema::table('invoice_histories', function (Blueprint $table) {
            $table->dropColumn(['event_type', 'message', 'actor_name']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['category', 'subtype', 'group_key', 'display_name']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['share_token', 'share_token_generated_at', 'manual_total_override_cents']);
        });
    }

    private function backfillInvoiceItemDisplayNames(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement(
                'UPDATE invoice_items SET display_name = substr(description, 1, 512) WHERE display_name IS NULL'
            );

            return;
        }

        DB::statement(
            'UPDATE invoice_items SET display_name = LEFT(description, 512) WHERE display_name IS NULL'
        );
    }

    private function backfillInvoiceHistoryEventTypes(): void
    {
        $status = InvoiceHistoryEventType::STATUS;
        $header = InvoiceHistoryEventType::HEADER_EDIT;
        $lineAdd = InvoiceHistoryEventType::LINE_ADD;

        $map = [
            'created' => $lineAdd,
            'updated' => $header,
            'sent' => $status,
            'payment_applied' => $status,
            'voided' => $status,
        ];

        foreach ($map as $action => $eventType) {
            DB::table('invoice_histories')
                ->whereNull('event_type')
                ->where('action', $action)
                ->update(['event_type' => $eventType]);
        }

        DB::table('invoice_histories')
            ->whereNull('event_type')
            ->update(['event_type' => $header]);
    }
};
