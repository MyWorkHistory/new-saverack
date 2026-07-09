<?php

namespace App\Jobs;

use App\Services\OrderDashboardSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RefreshOrderDashboardSectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var string */
    public $sectionKey;

    /** @var bool */
    public $fromIndex;

    public $timeout = 3600;

    public $tries = 1;

    public $maxExceptions = 1;

    public function __construct(string $sectionKey, bool $fromIndex = false)
    {
        $this->sectionKey = trim($sectionKey);
        $this->fromIndex = $fromIndex;
    }

    public function handle(OrderDashboardSnapshotService $snapshots): void
    {
        if ($this->fromIndex) {
            $snapshots->refreshSectionFromIndex($this->sectionKey);

            return;
        }

        $snapshots->refreshSection($this->sectionKey);
    }

    public function failed(Throwable $e): void
    {
        app(OrderDashboardSnapshotService::class)->markSectionFailed(
            $this->sectionKey,
            $e->getMessage() !== '' ? $e->getMessage() : 'Home dashboard refresh failed.'
        );
    }
}
