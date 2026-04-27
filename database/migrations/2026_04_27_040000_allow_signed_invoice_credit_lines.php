<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoice_items MODIFY quantity DECIMAL(14, 4) NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE invoice_items MODIFY unit_price_cents BIGINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE invoice_items MODIFY line_total_cents BIGINT NOT NULL DEFAULT 0');

        DB::statement('ALTER TABLE invoices MODIFY subtotal_cents BIGINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE invoices MODIFY tax_cents BIGINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE invoices MODIFY total_cents BIGINT NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE invoices MODIFY subtotal_cents BIGINT UNSIGNED NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE invoices MODIFY tax_cents BIGINT UNSIGNED NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE invoices MODIFY total_cents BIGINT UNSIGNED NOT NULL DEFAULT 0');

        DB::statement('ALTER TABLE invoice_items MODIFY line_total_cents BIGINT UNSIGNED NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE invoice_items MODIFY unit_price_cents BIGINT UNSIGNED NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE invoice_items MODIFY quantity DECIMAL(14, 4) NOT NULL DEFAULT 1');
    }
};
