<?php

namespace App\Jobs;

use App\Models\OrderDashboardSection;
use App\Services\OrderDashboardSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Live ShipHero refresh for Home dashboard primary totals (RTS, on-hold today, shipped).
 * Runs on the long queue so scheduler/web requests are not blocked by 64-account API fan-out.
 */
class RefreshPrimaryTotalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public $tries = 1;

    public $maxExceptions = 1;

    public function __construct()
    {
        $this->onQueue('database-long');
    }

    public function handle(OrderDashboardSnapshotService $snapshots): void
    {
        $snapshots->refreshPrimaryTotals();

        foreach (OrderDashboardSection::HOLD_KEYS as $key) {
            $snapshots->refreshSectionFromIndex($key);
        }
    }

    public function failed(Throwable $e): void
    {
        foreach ([
            OrderDashboardSection::KEY_READY_TO_SHIP,
            OrderDashboardSection::KEY_SHIPPED,
        ] as $key) {
            app(OrderDashboardSnapshotService::class)->markSectionFailed(
                $key,
                $e->getMessage() !== '' ? $e->getMessage() : 'Primary totals refresh failed.'
            );
        }
    }
}
