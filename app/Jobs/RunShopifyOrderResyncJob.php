<?php

namespace App\Jobs;

use App\Models\ClientAccountShopifyConnection;
use App\Services\ShopifyOrderSyncService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunShopifyOrderResyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MODE_UNFULFILLED = 'unfulfilled';

    public const MODE_AFTER_DATE = 'after_date';

    /** @var int */
    public $connectionId;

    /** @var string */
    public $mode;

    /** @var string|null YYYY-MM-DD */
    public $afterDate;

    public $timeout = 600;

    public $tries = 1;

    public function __construct(int $connectionId, string $mode, ?string $afterDate = null)
    {
        $this->connectionId = $connectionId;
        $this->mode = $mode;
        $this->afterDate = $afterDate;
        $this->onConnection((string) config('queue.default', 'database'));
    }

    public function handle(ShopifyOrderSyncService $orders): void
    {
        $connection = ClientAccountShopifyConnection::query()->find($this->connectionId);
        if ($connection === null || ! $connection->hasCredentials()) {
            Log::warning('shopify.order_resync.skipped', [
                'connection_id' => $this->connectionId,
                'reason' => 'missing_connection_or_credentials',
            ]);

            return;
        }

        Log::info('shopify.order_resync.started', [
            'connection_id' => $connection->id,
            'mode' => $this->mode,
            'after_date' => $this->afterDate,
        ]);

        try {
            if ($this->mode === self::MODE_AFTER_DATE) {
                if ($this->afterDate === null || trim($this->afterDate) === '') {
                    throw new \RuntimeException('After date is required.');
                }
                $count = $orders->syncOrdersCreatedAfter(
                    $connection,
                    Carbon::parse($this->afterDate)->utc()->startOfDay()
                );
            } else {
                $count = $orders->syncUnfulfilledOrders($connection);
            }

            Log::info('shopify.order_resync.finished', [
                'connection_id' => $connection->id,
                'mode' => $this->mode,
                'synced' => $count,
            ]);
        } catch (Throwable $e) {
            report($e);
            $connection->refresh();
            $connection->last_error = mb_substr('Order re-sync failed: '.$e->getMessage(), 0, 1000);
            $connection->save();

            throw $e;
        }
    }
}
