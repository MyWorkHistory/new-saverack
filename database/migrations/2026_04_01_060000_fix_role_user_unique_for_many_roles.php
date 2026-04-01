<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['role_id']);
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropUnique('role_user_user_id_unique');
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
