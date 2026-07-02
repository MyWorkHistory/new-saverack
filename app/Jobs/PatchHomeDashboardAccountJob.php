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

    public function handle(
        ShipHeroOrderQueueIndexService $index,
        OrderDashboardSnapshotService $snapshots
    ): void {
        if ($this->clientAccountId <= 0 || $this->queueTab === '') {
            return;
        }

        if ($index->isQueueTab($this->queueTab)) {
            $index->syncAccountQueue($this->clientAccountId, $this->queueTab, true);
        }

        $snapshots->patchAccountFromQueueTab($this->clientAccountId, $this->queueTab);
    }

    public function failed(Throwable $e): void
    {
        // syncAccountQueue marks account failed on exception.
    }
}
