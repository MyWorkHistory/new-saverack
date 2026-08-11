<?php

namespace App\Jobs;

use App\Models\ClientAccountShopifyConnection;
use App\Services\ShopifyBootstrapImportService;
use App\Services\ShopifyConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunShopifyBootstrapImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $connectionId;

    /** @var bool */
    public $registerWebhooks;

    public $timeout = 900;

    public $tries = 1;

    public function __construct(int $connectionId, bool $registerWebhooks = true)
    {
        $this->connectionId = $connectionId;
        $this->registerWebhooks = $registerWebhooks;
        $this->onConnection((string) config('queue.default', 'database'));
    }

    public function handle(
        ShopifyBootstrapImportService $bootstrap,
        ShopifyConnectionService $connections
    ): void {
        $connection = ClientAccountShopifyConnection::query()->find($this->connectionId);
        if ($connection === null || ! $connection->hasCredentials()) {
            return;
        }

        try {
            $bootstrap->importAll($connection);

            if ($this->registerWebhooks) {
                try {
                    $connections->registerWebhooks($connection);
                } catch (Throwable $e) {
                    Log::warning('shopify.webhooks.register_failed', [
                        'connection_id' => $connection->id,
                        'message' => $e->getMessage(),
                    ]);
                    $connection->last_error = mb_substr(
                        'Import completed but webhook registration failed: '.$e->getMessage(),
                        0,
                        1000
                    );
                    $connection->save();
                }
            }
        } catch (Throwable $e) {
            report($e);
            $connection->refresh();
            $connection->status = ClientAccountShopifyConnection::STATUS_ERROR;
            $connection->last_error = mb_substr($e->getMessage(), 0, 1000);
            $connection->save();

            throw $e;
        }
    }
}
