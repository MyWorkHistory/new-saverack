<?php

namespace App\Jobs;

use App\Services\ShipHeroInventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        try {
            $payload = $inventory->syncCatalogInventoryPage(
                $this->clientAccountId,
                $this->customerAccountId,
                100,
                $this->after,
                $this->syncMode
            );

            $pageInfo = is_array($payload['page_info'] ?? null) ? $payload['page_info'] : [];
            $hasNextPage = (bool) ($pageInfo['has_next_page'] ?? false);
            $endCursor = isset($pageInfo['end_cursor']) && is_string($pageInfo['end_cursor'])
                ? trim($pageInfo['end_cursor'])
                : null;

            if ($hasNextPage && $endCursor !== null && $endCursor !== '') {
                self::dispatch($this->clientAccountId, $this->customerAccountId, $endCursor, $this->syncMode);

                return;
            }

            if ($this->syncMode === ShipHeroInventoryService::CATALOG_SYNC_FULL) {
                $inventory->markCatalogSyncCompleted($this->clientAccountId);
            } else {
                $inventory->finalizeIncrementalCatalogSync($this->clientAccountId);
            }
        } catch (Throwable $e) {
            report($e);
            $inventory->markCatalogSyncFailed($this->clientAccountId);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        app(ShipHeroInventoryService::class)->markCatalogSyncFailed($this->clientAccountId);
    }
}
