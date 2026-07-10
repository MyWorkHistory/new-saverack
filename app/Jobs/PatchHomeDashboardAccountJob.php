<?php

namespace App\Jobs;

use App\Services\OrderDashboardSnapshotService;
use App\Services\ShipHeroOrderQueueIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PatchHomeDashboardAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $clientAccountId;

    /** @var string */
    public $queueTab;

    public $timeout = 3600;

    public $tries = 1;

    public function __construct(int $clientAccountId, string $queueTab)
    {
        $this->clientAccountId = $clientAccountId;
        $this->queueTab = trim($queueTab);
    }

    /** Queue sync after the HTTP response so web requests never block on ShipHero. */
    public static function dispatchAfterHttp(int $clientAccountId, string $queueTab): void
    {
        $queueTab = trim($queueTab);
        if ($clientAccountId <= 0 || $queueTab === '') {
            return;
        }

        static::dispatch($clientAccountId, $queueTab)->afterResponse();
    }

    public function handle(
        ShipHeroOrderQueueIndexService $index,
        OrderDashboardSnapshotService $snapshots
    ): void {
        if ($this->clientAccountId <= 0 || $this->queueTab === '') {
            return;
        }

        if ($index->isQueueTab($this->queueTab)) {
            $index->syncAccountQueue($this->clientAccountId, $this->queueTab);
        }

        $snapshots->patchAccountFromQueueTab($this->clientAccountId, $this->queueTab);
    }

    public function failed(Throwable $e): void
    {
        // syncAccountQueue marks account failed on exception.
    }
}
