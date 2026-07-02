<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoice_items')
            ->where('category', 'amazon prep')
            ->update(['category' => 'wholesale']);

        DB::table('custom_bill_items')
            ->where('line_type', 'amazon prep')
            ->update(['line_type' => 'wholesale']);
    }

    public function down(): void
    {
        DB::table('invoice_items')
            ->where('category', 'wholesale')
            ->update(['category' => 'amazon prep']);

        DB::table('custom_bill_items')
            ->where('line_type', 'wholesale')
            ->update(['line_type' => 'amazon prep']);
    }
};
