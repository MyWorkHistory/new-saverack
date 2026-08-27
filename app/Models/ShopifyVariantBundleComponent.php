<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyVariantBundleComponent extends Model
{
    protected $table = 'shopify_variant_bundle_components';

    protected $fillable = [
        'parent_variant_id',
        'component_variant_id',
        'quantity',
    ];

    protected $casts = [
        'parent_variant_id' => 'integer',
        'component_variant_id' => 'integer',
        'quantity' => 'integer',
    ];

    public function parentVariant(): BelongsTo
    {
        return $this->belongsTo(ShopifyProductVariant::class, 'parent_variant_id');
    }

    public function componentVariant(): BelongsTo
    {
        return $this->belongsTo(ShopifyProductVariant::class, 'component_variant_id');
    }
}
