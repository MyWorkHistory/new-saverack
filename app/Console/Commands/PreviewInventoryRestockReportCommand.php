<?php

namespace App\Console\Commands;

use App\Services\InventoryRestockReportService;
use Illuminate\Console\Command;
use Throwable;

class PreviewInventoryRestockReportCommand extends Command
{
    protected $signature = 'inventory:restock-preview
        {--warehouse-id= : ShipHero warehouse id}
        {--max-pages=10 : Pages to scan (partial preview)}
        {--max-pickable-qty= : Pickable qty threshold (default from config)}';

    protected $description = 'Preview low pickable-qty restock matches without writing a snapshot';

    public function handle(InventoryRestockReportService $reports): int
    {
        $warehouseId = $this->option('warehouse-id');
        $warehouseId = is_string($warehouseId) && trim($warehouseId) !== '' ? trim($warehouseId) : null;
        $maxPages = max(1, (int) $this->option('max-pages'));
        $maxPickableQtyOpt = $this->option('max-pickable-qty');
        $maxPickableQty = is_numeric($maxPickableQtyOpt) ? (int) $maxPickableQtyOpt : null;

        try {
            $preview = $reports->preview($warehouseId, $maxPages, $maxPickableQty);
            $this->info(sprintf(
                'Warehouse %s: %d matches (scanned %d products across %d pages%s).',
                (string) ($preview['warehouse_id'] ?? ''),
                (int) ($preview['match_count'] ?? 0),
                (int) ($preview['products_scanned'] ?? 0),
                (int) ($preview['pages_scanned'] ?? 0),
                ($preview['partial'] ?? false) ? ', partial' : ''
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
