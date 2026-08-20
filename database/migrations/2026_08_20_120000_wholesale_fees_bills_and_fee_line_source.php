<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->prepareWholesaleOrderFeeLines();

        if (! Schema::hasTable('wholesale_bills')) {
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
        }

        if (! Schema::hasTable('wholesale_bill_items')) {
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
        }

        if (! Schema::hasTable('wholesale_bill_histories')) {
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
        }

        if (! Schema::hasColumn('wholesale_orders', 'wholesale_bill_id')) {
            Schema::table('wholesale_orders', function (Blueprint $table) {
                $table->foreignId('wholesale_bill_id')->nullable()->after('id')
                    ->constrained('wholesale_bills')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('wholesale_orders', 'wholesale_bill_id')) {
            Schema::table('wholesale_orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('wholesale_bill_id');
            });
        }

        Schema::dropIfExists('wholesale_bill_histories');
        Schema::dropIfExists('wholesale_bill_items');
        Schema::dropIfExists('wholesale_bills');

        if (Schema::hasColumn('wholesale_order_fee_lines', 'client_account_fee_id')) {
            $this->dropForeignKeyIfExists('wholesale_order_fee_lines', 'client_account_fee_id');
        }
        $this->dropForeignKeyIfExists('wholesale_order_fee_lines', 'wholesale_order_id');
        $this->dropIndexIfExists(
            'wholesale_order_fee_lines',
            'wholesale_order_fee_lines_wholesale_order_id_line_type_index'
        );

        Schema::table('wholesale_order_fee_lines', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('wholesale_order_fee_lines', 'source')) {
                $cols[] = 'source';
            }
            if (Schema::hasColumn('wholesale_order_fee_lines', 'client_account_fee_id')) {
                $cols[] = 'client_account_fee_id';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        if (! $this->indexExists('wholesale_order_fee_lines', 'wholesale_order_fee_lines_wholesale_order_id_line_type_unique')) {
            Schema::table('wholesale_order_fee_lines', function (Blueprint $table) {
                $table->unique(['wholesale_order_id', 'line_type']);
            });
        }

        if (! $this->foreignKeyExists('wholesale_order_fee_lines', 'wholesale_order_id')) {
            Schema::table('wholesale_order_fee_lines', function (Blueprint $table) {
                $table->foreign('wholesale_order_id')
                    ->references('id')
                    ->on('wholesale_orders')
                    ->cascadeOnDelete();
            });
        }
    }

    private function prepareWholesaleOrderFeeLines(): void
    {
        // MySQL may use the composite unique as the only supporting index for the FK.
        $this->dropForeignKeyIfExists('wholesale_order_fee_lines', 'wholesale_order_id');
        $this->dropIndexIfExists(
            'wholesale_order_fee_lines',
            'wholesale_order_fee_lines_wholesale_order_id_line_type_unique'
        );

        Schema::table('wholesale_order_fee_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('wholesale_order_fee_lines', 'source')) {
                $table->string('source', 32)->default('wholesale')->after('line_type');
            }
            if (! Schema::hasColumn('wholesale_order_fee_lines', 'client_account_fee_id')) {
                $after = Schema::hasColumn('wholesale_order_fee_lines', 'source') ? 'source' : 'line_type';
                $table->unsignedBigInteger('client_account_fee_id')->nullable()->after($after);
            }
        });

        if (! $this->indexExists('wholesale_order_fee_lines', 'wholesale_order_fee_lines_wholesale_order_id_line_type_index')) {
            Schema::table('wholesale_order_fee_lines', function (Blueprint $table) {
                $table->index(['wholesale_order_id', 'line_type']);
            });
        }

        if (! $this->foreignKeyExists('wholesale_order_fee_lines', 'wholesale_order_id')) {
            Schema::table('wholesale_order_fee_lines', function (Blueprint $table) {
                $table->foreign('wholesale_order_id')
                    ->references('id')
                    ->on('wholesale_orders')
                    ->cascadeOnDelete();
            });
        }

        if (
            Schema::hasColumn('wholesale_order_fee_lines', 'client_account_fee_id')
            && ! $this->foreignKeyExists('wholesale_order_fee_lines', 'client_account_fee_id')
        ) {
            Schema::table('wholesale_order_fee_lines', function (Blueprint $table) {
                $table->foreign('client_account_fee_id')
                    ->references('id')
                    ->on('client_account_fees')
                    ->nullOnDelete();
            });
        }
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        $row = DB::selectOne(
            'select constraint_name as name
             from information_schema.key_column_usage
             where table_schema = database()
               and table_name = ?
               and column_name = ?
               and referenced_table_name is not null
             limit 1',
            [$table, $column]
        );

        return $row !== null;
    }

    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        $row = DB::selectOne(
            'select constraint_name as name
             from information_schema.key_column_usage
             where table_schema = database()
               and table_name = ?
               and column_name = ?
               and referenced_table_name is not null
             limit 1',
            [$table, $column]
        );
        if ($row === null || empty($row->name)) {
            return;
        }

        DB::statement('alter table `'.$table.'` drop foreign key `'.$row->name.'`');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = DB::selectOne(
            'select index_name as name
             from information_schema.statistics
             where table_schema = database()
               and table_name = ?
               and index_name = ?
             limit 1',
            [$table, $indexName]
        );

        return $row !== null;
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName)) {
            return;
        }

        DB::statement('alter table `'.$table.'` drop index `'.$indexName.'`');
    }
};
