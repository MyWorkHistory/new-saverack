<?php

namespace App\Jobs;

use App\Services\ShipHeroInventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeInventoryCatalogSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $clientAccountId;

    /** @var int|null */
    public $afterId;

    public $timeout = 300;

    public $tries = 1;

    public function __construct(int $clientAccountId, ?int $afterId = null)
    {
        $this->clientAccountId = $clientAccountId;
        $this->afterId = $afterId !== null && $afterId > 0 ? $afterId : null;
    }

    public function handle(ShipHeroInventoryService $inventory): void
    {
        try {
            $nextAfterId = $inventory->finalizeIncrementalCatalogSyncBatch($this->clientAccountId, $this->afterId);
            if ($nextAfterId !== null) {
                $inventory->dispatchFinalizeCatalogSyncJob($this->clientAccountId, $nextAfterId);

                return;
            }

            $inventory->markCatalogSyncCompleted($this->clientAccountId);
            Log::info('inventory.catalog_sync.completed', [
                'client_account_id' => $this->clientAccountId,
                'sync_mode' => ShipHeroInventoryService::CATALOG_SYNC_INCREMENTAL,
            ]);
        } catch (Throwable $e) {
            report($e);
            $inventory->markCatalogSyncFailed($this->clientAccountId);
            Log::warning('inventory.catalog_sync.failed', [
                'client_account_id' => $this->clientAccountId,
                'phase' => 'finalize',
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        app(ShipHeroInventoryService::class)->markCatalogSyncFailed($this->clientAccountId);
    }
}
