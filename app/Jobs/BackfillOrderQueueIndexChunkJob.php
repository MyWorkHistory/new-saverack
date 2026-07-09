<?php

namespace App\Jobs;

use App\Models\ShipHeroOrderQueueIndex;
use App\Services\ShipHeroOrderQueueIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackfillOrderQueueIndexChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $clientAccountId;

    /** @var string */
    public $tab;

    /** @var string */
    public $dateFrom;

    /** @var string */
    public $dateTo;

    /** @var bool */
    public $purgeStale;

    public $timeout = 3600;

    public $tries = 1;

    public function __construct(
        int $clientAccountId,
        string $tab,
        string $dateFrom,
        string $dateTo,
        bool $purgeStale = false
    ) {
        $this->clientAccountId = $clientAccountId;
        $this->tab = strtolower(trim($tab));
        $this->dateFrom = trim($dateFrom);
        $this->dateTo = trim($dateTo);
        $this->purgeStale = $purgeStale;
    }

    public function handle(ShipHeroOrderQueueIndexService $index): void
    {
        $maxPages = $this->tab === ShipHeroOrderQueueIndex::KIND_SHIPPED ? 500 : 100;

        try {
            $stats = $index->syncAccountQueueRange(
                $this->clientAccountId,
                $this->tab,
                $this->dateFrom,
                $this->dateTo,
                $this->purgeStale,
                $maxPages,
                false
            );

            Log::info('order_queue_index.backfill_chunk.completed', [
                'client_account_id' => $this->clientAccountId,
                'tab' => $this->tab,
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
                'pages' => $stats['pages'] ?? 0,
                'rows_upserted' => $stats['rows_upserted'] ?? 0,
                'truncated' => $stats['truncated'] ?? false,
            ]);
        } catch (Throwable $e) {
            Log::warning('order_queue_index.backfill_chunk.failed', [
                'client_account_id' => $this->clientAccountId,
                'tab' => $this->tab,
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
