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

class PushShopifyVariantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $variantId;

    /** @var array<string, mixed> */
    public $fields;

    public $timeout = 120;

    public $tries = 3;

    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(int $variantId, array $fields)
    {
        $this->variantId = $variantId;
        $this->fields = $fields;
        $this->onConnection((string) config('queue.default', 'database'));
    }

    public function handle(ShopifyProductSyncService $products): void
    {
        $variant = ShopifyProductVariant::query()->with(['product', 'connection'])->find($this->variantId);
        if ($variant === null) {
            return;
        }

        try {
            $products->pushVariantToShopify($variant, $this->fields);
            if (
                array_key_exists('weight', $this->fields)
                || ! empty($this->fields['sync_shipping_package'])
                || array_key_exists('shipping_package_id', $this->fields)
            ) {
                \Illuminate\Support\Facades\Cache::put(
                    'shopify.crm_shipping_push.'.$this->variantId,
                    1,
                    now()->addSeconds(120)
                );
            }
            Log::info('shopify.variant.push_ok', [
                'variant_id' => $this->variantId,
                'shopify_variant_id' => $variant->shopify_variant_id,
            ]);
        } catch (Throwable $e) {
            Log::warning('shopify.variant.push_failed', [
                'variant_id' => $this->variantId,
                'message' => $e->getMessage(),
            ]);
            $connection = $variant->connection;
            if ($connection !== null) {
                $connection->last_error = mb_substr('Variant push failed: '.$e->getMessage(), 0, 1000);
                $connection->save();
            }
            throw $e;
        }
    }
}
