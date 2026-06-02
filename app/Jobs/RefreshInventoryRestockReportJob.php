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

    /** Full scan (all chunks in one job). */
    public $timeout = 3600;

    public $tries = 1;

    public $maxExceptions = 1;

    public function __construct(?string $warehouseId = null)
    {
        $this->warehouseId = is_string($warehouseId) && trim($warehouseId) !== ''
            ? trim($warehouseId)
            : null;

        if ($this->shouldUseLongQueueConnection()) {
            $connection = app(InventoryRestockReportService::class)->restockQueueConnection();
            if ($connection !== null) {
                $this->onConnection($connection);
            }
        }
    }

    public function handle(InventoryRestockReportService $reports): void
    {
        try {
            $reports->runFullRefreshUntilDone($this->warehouseId);
        } catch (Throwable $e) {
            report($e);
            $reports->markRefreshFailed(
                $this->warehouseId,
                $e->getMessage() !== '' ? $e->getMessage() : 'Restock report refresh failed.'
            );
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        app(InventoryRestockReportService::class)->markRefreshFailed(
            $this->warehouseId,
            $e->getMessage() !== '' ? $e->getMessage() : 'Restock report refresh failed.'
        );
    }

    private function shouldUseLongQueueConnection(): bool
    {
        return strtolower(trim((string) config('services.shiphero.restock_dispatch_mode', 'after_response'))) === 'queue';
    }
}
