<?php

namespace App\Jobs;

use App\Models\ShopifyProductVariant;
use App\Services\ShopifyProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push CRM inventory levels to Shopify after the HTTP response.
 * Intentionally not ShouldQueue — runs in-process afterResponse so UI never waits.
 */
class PushShopifyVariantInventoryJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $variantId;

    public function __construct(int $variantId)
    {
        $this->variantId = $variantId;
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
            Log::warning('shopify.inventory.push_after_warehouse_failed', [
                'variant_id' => $this->variantId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
