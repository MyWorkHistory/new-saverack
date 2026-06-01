<?php

namespace App\Console\Commands;

use App\Services\InventoryRestockReportService;
use Illuminate\Console\Command;
use Throwable;

class RefreshInventoryRestockReportCommand extends Command
{
    protected $signature = 'inventory:refresh-restock-report {--warehouse-id= : ShipHero warehouse id}';

    protected $description = 'Compute and store the admin inventory restock report snapshot';

    public function handle(InventoryRestockReportService $reports): int
    {
        $warehouseId = $this->option('warehouse-id');
        $warehouseId = is_string($warehouseId) && trim($warehouseId) !== '' ? trim($warehouseId) : null;

        try {
            $result = $reports->refresh($warehouseId);
            $this->info(sprintf(
                'Restock report saved for warehouse %s: %d rows in %d ms.',
                $result['warehouse_id'],
                $result['row_count'],
                $result['duration_ms']
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
