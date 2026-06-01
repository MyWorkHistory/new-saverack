<?php

namespace App\Jobs;

use App\Services\InventoryRestockReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RefreshInventoryRestockReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var string|null */
    public $warehouseId;

    public $timeout = 3600;

    public function __construct(?string $warehouseId = null)
    {
        $this->warehouseId = is_string($warehouseId) && trim($warehouseId) !== ''
            ? trim($warehouseId)
            : null;
    }

    public function handle(InventoryRestockReportService $reports): void
    {
        try {
            $reports->refresh($this->warehouseId);
        } catch (Throwable $e) {
            report($e);
            throw $e;
        }
    }
}
