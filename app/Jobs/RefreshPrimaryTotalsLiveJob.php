<?php

namespace App\Jobs;

use App\Services\OrderDashboardSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Accurate dashboard totals from live ShipHero (sequential, credit-aware). ~5 min for 64 accounts.
 */
class RefreshPrimaryTotalsLiveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200;

    public $tries = 1;

    public function __construct()
    {
        $this->onQueue('database-long');
    }

    public function handle(OrderDashboardSnapshotService $snapshots): void
    {
        $snapshots->refreshPrimaryTotals(true);
    }

    public function failed(Throwable $e): void
    {
        app(OrderDashboardSnapshotService::class)->clearStalePrimaryRunning();
    }
}
