<?php

namespace App\Jobs;

use App\Models\ClientAccountShopifyConnection;
use App\Services\ShopifyProductCsvService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportShopifyProductsCsvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $connectionId;

    /** @var list<array{row:int, values:array<string, mixed>}> */
    public $rows;

    public $timeout = 1800;

    public $tries = 1;

    /**
     * @param  list<array{row:int, values:array<string, mixed>}>  $rows
     */
    public function __construct(int $connectionId, array $rows)
    {
        $this->connectionId = $connectionId;
        $this->rows = $rows;
        $this->onConnection((string) config('queue.default', 'database'));
    }

    public function handle(ShopifyProductCsvService $csv): void
    {
        if ($this->rows === []) {
            return;
        }

        $connection = ClientAccountShopifyConnection::query()->find($this->connectionId);
        if ($connection === null) {
            return;
        }

        @set_time_limit(0);
        $result = $csv->import($this->rows, $connection);

        Log::info('shopify.products.import_done', [
            'connection_id' => $this->connectionId,
            'created' => $result['created'],
            'updated' => $result['updated'],
            'failed' => $result['failed'],
            'errors' => array_slice($result['errors'], 0, 20),
        ]);
    }
}
