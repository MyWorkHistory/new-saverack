<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyWarehouseLocationItem extends Model
{
    protected $table = 'shopify_warehouse_location_items';

    protected $fillable = [
        'location_id',
        'shopify_variant_id',
        'available',
    ];

    protected $casts = [
        'location_id' => 'integer',
        'shopify_variant_id' => 'integer',
        'available' => 'integer',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(ShopifyWarehouseLocation::class, 'location_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ShopifyProductVariant::class, 'shopify_variant_id');
    }
}
