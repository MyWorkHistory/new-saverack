<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_restock_snapshots', function (Blueprint $table) {
            $table->text('scan_cursor')->nullable()->after('progress_page');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_restock_snapshots', function (Blueprint $table) {
            $table->dropColumn('scan_cursor');
        });
    }
};
