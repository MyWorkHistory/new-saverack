<?php

namespace App\Jobs;

use App\Models\ShopifyProductVariant;
use App\Services\ShopifyProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push CRM inventory levels to Shopify after warehouse qty changes.
 */
class PushShopifyVariantInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $variantId;

    /** @var int */
    public $timeout = 120;

    /** @var int */
    public $tries = 3;

    public function __construct(int $variantId)
    {
        $this->variantId = $variantId;
        $this->onConnection((string) config('queue.default', 'database'));
    }

    public function handle(ShopifyProductSyncService $sync): void
    {
        if ($this->variantId <= 0) {
            return;
        }

        $variant = ShopifyProductVariant::query()
            ->with('connection')
            ->find($this->variantId);
        if ($variant === null) {
            return;
        }

        try {
            @set_time_limit(120);
            $pushed = $sync->pushInventoryToShopify($variant);
            Log::info('shopify.inventory.push_after_warehouse', [
                'variant_id' => $this->variantId,
                'pushed' => $pushed,
            ]);
        } catch (Throwable $e) {
            report($e);
            Log::warning('shopify.inventory.push_after_warehouse_failed', [
                'variant_id' => $this->variantId,
                'message' => $e->getMessage(),
            ]);
            $connection = $variant->connection;
            if ($connection !== null) {
                $connection->last_error = mb_substr('Inventory push failed: '.$e->getMessage(), 0, 1000);
                $connection->save();
            }
            throw $e;
        }
    }
}
