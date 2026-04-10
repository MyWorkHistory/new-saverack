<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy installs had role_user.user_id unique (one role per user).
     * New installs create role_user with unique(user_id, role_id) from the start.
     * This migration must be a no-op on fresh databases.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $db = DB::getDatabaseName();
            $rows = DB::select(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$db, $table, $indexName]
            );

            return count($rows) > 0;
        }

        if ($driver === 'sqlite') {
            foreach (DB::select('SELECT name FROM sqlite_master WHERE type = ? AND tbl_name = ?', ['index', $table]) as $row) {
                if (($row->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    public function up(): void
    {
        if (! Schema::hasTable('role_user')) {
            return;
        }

        $oldSingleUserUnique = 'role_user_user_id_unique';
        $compositeUnique = 'role_user_user_id_role_id_unique';

        if (! $this->indexExists('role_user', $oldSingleUserUnique)) {
            if ($this->indexExists('role_user', $compositeUnique)) {
                return;
            }

            Schema::table('role_user', function (Blueprint $table) {
                $table->unique(['user_id', 'role_id']);
            });

            return;
        }

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['role_id']);
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropUnique($oldSingleUserUnique);
            $table->unique(['user_id', 'role_id']);
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_user')) {
            return;
        }

        $compositeUnique = 'role_user_user_id_role_id_unique';
        if (! $this->indexExists('role_user', $compositeUnique)) {
            return;
        }

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['role_id']);
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'role_id']);
            $table->unique('user_id');
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
        });
    }
};
