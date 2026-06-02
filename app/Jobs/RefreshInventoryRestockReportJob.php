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

    /** One chunk per job; full scan chains additional jobs. */
    public $timeout = 600;

    public $tries = 1;

    public $maxExceptions = 1;

    public $failOnTimeout = true;

    public function __construct(?string $warehouseId = null)
    {
        $this->warehouseId = is_string($warehouseId) && trim($warehouseId) !== ''
            ? trim($warehouseId)
            : null;

        $connection = app(InventoryRestockReportService::class)->restockQueueConnection();
        if ($connection !== null) {
            $this->onConnection($connection);
        }
    }

    public function handle(InventoryRestockReportService $reports): void
    {
        try {
            $result = $reports->refreshNextChunk($this->warehouseId);
            if ($result['has_more'] ?? false) {
                $reports->dispatchNextRefreshChunk($this->warehouseId);
            }
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
}
