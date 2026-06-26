<?php

namespace App\Jobs;

use App\Services\InventoryRestockReportService;
use App\Services\PutAwayInventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RefreshPutAwayReceivingSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var string */
    public $warehouseId;

    /** Full warehouse scan in one job. */
    public $timeout = 3600;

    public $tries = 1;

    public $maxExceptions = 1;

    public function __construct(string $warehouseId)
    {
        $this->warehouseId = trim($warehouseId);

        if ($this->shouldUseLongQueueConnection()) {
            $connection = app(InventoryRestockReportService::class)->restockQueueConnection();
            if ($connection !== null) {
                $this->onConnection($connection);
            }
        }
    }

    public function handle(PutAwayInventoryService $putAway): void
    {
        try {
            $putAway->runReceivingRefresh($this->warehouseId);
        } catch (Throwable $e) {
            report($e);
            $putAway->markReceivingRefreshFailed(
                $this->warehouseId,
                $e->getMessage() !== '' ? $e->getMessage() : 'Put away receiving refresh failed.'
            );
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        app(PutAwayInventoryService::class)->markReceivingRefreshFailed(
            $this->warehouseId,
            $e->getMessage() !== '' ? $e->getMessage() : 'Put away receiving refresh failed.'
        );
    }

    private function shouldUseLongQueueConnection(): bool
    {
        return strtolower(trim((string) config('services.shiphero.restock_dispatch_mode', 'after_response'))) === 'queue';
    }
}
