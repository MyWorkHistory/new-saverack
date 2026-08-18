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
            Log::warning('shopify.bootstrap.skipped', [
                'connection_id' => $this->connectionId,
                'reason' => 'missing_connection_or_credentials',
            ]);
            if ($connection !== null && $connection->status === ClientAccountShopifyConnection::STATUS_IMPORTING) {
                $connection->status = ClientAccountShopifyConnection::STATUS_ERROR;
                $connection->last_error = 'Shopify import stalled (missing credentials).';
                $connection->save();
            }

            return;
        }

        Log::info('shopify.bootstrap.started', [
            'connection_id' => $connection->id,
            'shop' => $connection->normalizedShopDomain(),
        ]);

        try {
            $result = $bootstrap->importAll($connection);

            if ($this->registerWebhooks) {
                try {
                    $connections->registerWebhooks($connection->fresh() ?? $connection);
                } catch (Throwable $e) {
                    Log::warning('shopify.webhooks.register_failed', [
                        'connection_id' => $connection->id,
                        'message' => $e->getMessage(),
                    ]);
                    $connection->refresh();
                    $connection->last_error = mb_substr(
                        'Import completed but webhook registration failed: '.$e->getMessage(),
                        0,
                        1000
                    );
                    $connection->save();
                }
            }

            Log::info('shopify.bootstrap.finished', [
                'connection_id' => $connection->id,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($e->getMessage());

            throw $e;
        }

        $fresh = ClientAccountShopifyConnection::query()->find($this->connectionId);
        if ($fresh !== null && $fresh->status === ClientAccountShopifyConnection::STATUS_IMPORTING) {
            $this->markFailed('Shopify import stalled. Click Full Re-Import.');
        }
    }

    public function failed(?Throwable $e): void
    {
        $message = $e !== null && $e->getMessage() !== ''
            ? $e->getMessage()
            : 'Shopify import job failed.';
        $this->markFailed($message);
    }

    private function markFailed(string $message): void
    {
        $connection = ClientAccountShopifyConnection::query()->find($this->connectionId);
        if ($connection === null) {
            return;
        }
        if ($connection->status === ClientAccountShopifyConnection::STATUS_CONNECTED
            && $connection->last_sync_at !== null) {
            // Import finished; failure was likely webhook registration after status flip.
            return;
        }

        $connection->status = ClientAccountShopifyConnection::STATUS_ERROR;
        $connection->last_error = mb_substr($message, 0, 1000);
        $connection->save();
    }
}
