<?php

namespace App\Jobs;

use App\Models\WholesaleOrder;
use App\Services\WholesaleOrderLinesCsvImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs after the HTTP response (dispatchAfterResponse) so Cloudflare never waits.
 * Local catalog only — intentionally not ShouldQueue.
 */
class ImportWholesaleOrderLinesCsvJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $wholesaleOrderId;

    /** @var list<array{row: int, name: string, sku: string, quantity: int}> */
    public $rows;

    /**
     * @param  list<array{row: int, name: string, sku: string, quantity: int}>  $rows
     */
    public function __construct(int $wholesaleOrderId, array $rows)
    {
        $this->wholesaleOrderId = $wholesaleOrderId;
        $this->rows = $rows;
    }

    public function handle(WholesaleOrderLinesCsvImportService $csv): void
    {
        if ($this->rows === []) {
            return;
        }

        $order = WholesaleOrder::query()->find($this->wholesaleOrderId);
        if ($order === null) {
            return;
        }

        if (! $order->canEditLines()) {
            Log::warning('wholesale.lines.csv_import_skipped_locked', [
                'wholesale_order_id' => $this->wholesaleOrderId,
            ]);

            return;
        }

        @set_time_limit(120);
        $result = $csv->apply($order, $this->rows);

        Log::info('wholesale.lines.csv_import_done', [
            'wholesale_order_id' => $this->wholesaleOrderId,
            'queued_rows' => count($this->rows),
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'errors' => array_slice($result['errors'], 0, 20),
        ]);
    }
}
