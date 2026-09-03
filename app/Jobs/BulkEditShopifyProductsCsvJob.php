<?php

namespace App\Jobs;

use App\Services\ShopifyProductCsvService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BulkEditShopifyProductsCsvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int|null */
    public $clientAccountId;

    /** @var list<array{row:int, values:array<string, mixed>}> */
    public $rows;

    public $timeout = 1800;

    public $tries = 1;

    /**
     * @param  list<array{row:int, values:array<string, mixed>}>  $rows
     */
    public function __construct(?int $clientAccountId, array $rows)
    {
        $this->clientAccountId = $clientAccountId;
        $this->rows = $rows;
        $this->onConnection((string) config('queue.default', 'database'));
    }

    public function handle(ShopifyProductCsvService $csv): void
    {
        if ($this->rows === []) {
            return;
        }

        @set_time_limit(0);
        $result = $csv->bulkEdit($this->rows, $this->clientAccountId);

        Log::info('shopify.products.bulk_edit_done', [
            'client_account_id' => $this->clientAccountId,
            'updated' => $result['updated'],
            'missing' => $result['missing'],
            'failed' => $result['failed'],
            'errors' => array_slice($result['errors'], 0, 20),
        ]);
    }
}
