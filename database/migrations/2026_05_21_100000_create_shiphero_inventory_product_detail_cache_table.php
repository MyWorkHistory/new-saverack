<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'shiphero_inventory_product_detail_cache';

    private const FK = 'sh_inv_detail_cache_acct_fk';

    private const UNIQUE = 'shiphero_inv_detail_cache_account_sku_unique';

    private const INDEX = 'shiphero_inv_detail_cache_account_sku_search';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->createTable();

            return;
        }

        $this->ensureForeignKey();
        $this->ensureUniqueIndex();
        $this->ensureSearchIndex();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function createTable(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_account_id');
            $table->foreign('client_account_id', self::FK)
                ->references('id')
                ->on('client_accounts')
                ->cascadeOnDelete();
            $table->string('sku', 255);
            $table->string('sku_search', 255);
            $table->json('product_json')->nullable();
            $table->json('allocated_orders_json')->nullable();
            $table->json('backorder_orders_json')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['client_account_id', 'sku'], self::UNIQUE);
            $table->index(['client_account_id', 'sku_search'], self::INDEX);
        });
    }

    private function ensureForeignKey(): void
    {
        if ($this->constraintExists(self::FK)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->foreign('client_account_id', self::FK)
                ->references('id')
                ->on('client_accounts')
                ->cascadeOnDelete();
        });
    }

    private function ensureUniqueIndex(): void
    {
        if ($this->indexExists(self::UNIQUE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unique(['client_account_id', 'sku'], self::UNIQUE);
        });
    }

    private function ensureSearchIndex(): void
    {
        if ($this->indexExists(self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->index(['client_account_id', 'sku_search'], self::INDEX);
        });
    }

    private function constraintExists(string $name): bool
    {
        $schema = Schema::getConnection()->getDatabaseName();

        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
             LIMIT 1',
            [$schema, self::TABLE, $name]
        ) !== null;
    }

    private function indexExists(string $name): bool
    {
        $schema = Schema::getConnection()->getDatabaseName();

        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
             LIMIT 1',
            [$schema, self::TABLE, $name]
        ) !== null;
    }
};
