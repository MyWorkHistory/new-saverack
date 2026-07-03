<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_account_returns', function (Blueprint $table) {
            $table->unsignedTinyInteger('return_bin_number')->nullable()->after('processed_by_user_id');
        });

        Schema::table('client_account_return_lines', function (Blueprint $table) {
            $table->unsignedTinyInteger('return_bin_number')->nullable()->after('sort_order');
            $table->unsignedInteger('return_bin_remaining_qty')->nullable()->after('return_bin_number');
            $table->index(['return_bin_number', 'return_bin_remaining_qty'], 'ca_return_lines_bin_rem_idx');
        });
    }

    public function down(): void
    {
        Schema::table('client_account_return_lines', function (Blueprint $table) {
            $table->dropIndex('ca_return_lines_bin_rem_idx');
            $table->dropColumn(['return_bin_number', 'return_bin_remaining_qty']);
        });

        Schema::table('client_account_returns', function (Blueprint $table) {
            $table->dropColumn('return_bin_number');
        });
    }
};
