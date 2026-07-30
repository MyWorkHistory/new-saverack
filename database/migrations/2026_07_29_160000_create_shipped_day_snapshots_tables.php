<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipped_day_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->unsignedInteger('total_count')->default(0);
            $table->timestamp('captured_at')->nullable();
            $table->string('timezone', 64)->default('America/New_York');
            $table->timestamps();

            $table->unique('snapshot_date');
        });

        Schema::create('shipped_day_snapshot_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipped_day_snapshot_id')
                ->constrained('shipped_day_snapshots')
                ->cascadeOnDelete();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->string('account_name');
            $table->unsignedInteger('orders_count')->default(0);
            $table->timestamps();

            $table->unique(
                ['shipped_day_snapshot_id', 'client_account_id'],
                'shipped_day_snap_acct_unique'
            );
            $table->index('client_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipped_day_snapshot_accounts');
        Schema::dropIfExists('shipped_day_snapshots');
    }
};
