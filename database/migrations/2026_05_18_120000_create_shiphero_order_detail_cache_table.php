<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'shiphero_order_detail_cache';

    private const FK = 'sh_order_detail_cache_acct_fk';

    private const UNIQUE = 'shiphero_order_detail_cache_account_order_unique';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_account_id');
            $table->foreign('client_account_id', self::FK)
                ->references('id')
                ->on('client_accounts')
                ->cascadeOnDelete();
            $table->string('order_id', 255);
            $table->json('order_json')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['client_account_id', 'order_id'], self::UNIQUE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
