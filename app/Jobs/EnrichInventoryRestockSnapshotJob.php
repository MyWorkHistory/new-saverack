<?php

namespace App\Jobs;

use App\Models\InventoryRestockBetaSnapshot;
use App\Services\InventoryRestockBetaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class EnrichInventoryRestockSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $snapshotId;

    public $timeout = 600;

    public $tries = 1;

    public function __construct(int $snapshotId)
    {
        $this->snapshotId = $snapshotId;

        if ($this->shouldUseLongQueueConnection()) {
            $connection = app(InventoryRestockBetaService::class)->restockQueueConnection();
            if ($connection !== null) {
                $this->onConnection($connection);
            }
        }
    }

    public function handle(InventoryRestockBetaService $restockBeta): void
    {
        $restockBeta->runEnrichmentForSnapshot($this->snapshotId);
    }

    public function failed(Throwable $e): void
    {
        $snapshot = InventoryRestockBetaSnapshot::query()->find($this->snapshotId);
        if ($snapshot === null) {
            return;
        }

        $snapshot->enrichment_status = InventoryRestockBetaService::ENRICHMENT_FAILED;
        $snapshot->enrichment_error = $e->getMessage() !== ''
            ? $e->getMessage()
            : 'Restock enrichment failed.';
        $snapshot->save();
    }

    private function shouldUseLongQueueConnection(): bool
    {
        return strtolower(trim((string) config('services.shiphero.restock_dispatch_mode', 'after_response'))) === 'queue';
    }
}
