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

class SyncInventoryCatalogPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $clientAccountId;

    /** @var string */
    public $customerAccountId;

    /** @var string|null */
    public $after;

    /** @var string */
    public $syncMode;

    public $timeout = 300;

    public $tries = 1;

    public function __construct(
        int $clientAccountId,
        string $customerAccountId,
        ?string $after = null,
        string $syncMode = ShipHeroInventoryService::CATALOG_SYNC_INCREMENTAL
    ) {
        $this->clientAccountId = $clientAccountId;
        $this->customerAccountId = trim($customerAccountId);
        $this->after = is_string($after) && trim($after) !== '' ? trim($after) : null;
        $this->syncMode = $syncMode;
    }

    public function handle(ShipHeroInventoryService $inventory): void
    {
        $deadline = microtime(true) + 240;
        $maxPages = $inventory->catalogSyncPagesPerJob();
        $after = $this->after;
        $pagesProcessed = 0;
        $lastPayload = null;

        try {
            do {
                $lastPayload = $inventory->syncCatalogInventoryPage(
                    $this->clientAccountId,
                    $this->customerAccountId,
                    ShipHeroInventoryService::CATALOG_SYNC_PAGE_SIZE,
                    $after,
                    $this->syncMode
                );
                $pagesProcessed++;

                $pageInfo = is_array($lastPayload['page_info'] ?? null) ? $lastPayload['page_info'] : [];
                $hasNextPage = (bool) ($pageInfo['has_next_page'] ?? false);
                $endCursor = isset($pageInfo['end_cursor']) && is_string($pageInfo['end_cursor'])
                    ? trim($pageInfo['end_cursor'])
                    : null;

                if (! $hasNextPage || $endCursor === null || $endCursor === '') {
                    $after = null;
                    break;
                }

                $after = $endCursor;
            } while ($pagesProcessed < $maxPages && microtime(true) < $deadline);

            if ($after !== null && $after !== '') {
                $inventory->dispatchCatalogPageJob(
                    $this->clientAccountId,
                    $this->customerAccountId,
                    $after,
                    $this->syncMode
                );

                return;
            }

            if ($this->syncMode === ShipHeroInventoryService::CATALOG_SYNC_FULL) {
                $inventory->markCatalogSyncCompleted($this->clientAccountId);
                Log::info('inventory.catalog_sync.completed', [
                    'client_account_id' => $this->clientAccountId,
                    'sync_mode' => ShipHeroInventoryService::CATALOG_SYNC_FULL,
                    'pages_in_job' => $pagesProcessed,
                ]);

                return;
            }

            $inventory->dispatchFinalizeCatalogSyncJob($this->clientAccountId);
        } catch (Throwable $e) {
            report($e);
            $inventory->markCatalogSyncFailed($this->clientAccountId, $e->getMessage());
            Log::warning('inventory.catalog_sync.failed', [
                'client_account_id' => $this->clientAccountId,
                'phase' => 'page',
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        app(ShipHeroInventoryService::class)->markCatalogSyncFailed($this->clientAccountId, $e->getMessage());
    }
}
