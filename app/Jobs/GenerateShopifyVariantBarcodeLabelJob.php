<?php

namespace App\Jobs;

use App\Models\ShopifyProductVariant;
use App\Services\ShopifyVariantBarcodeLabelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateShopifyVariantBarcodeLabelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int */
    public $variantId;

    public function __construct(int $variantId)
    {
        $this->variantId = $variantId;
    }

    public function handle(ShopifyVariantBarcodeLabelService $labels): void
    {
        $variant = ShopifyProductVariant::query()->with('product')->find($this->variantId);
        if ($variant === null) {
            return;
        }

        try {
            $labels->ensureLabel($variant, true);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
