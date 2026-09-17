<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyPackagingLocationItem extends Model
{
    protected $table = 'shopify_packaging_location_items';

    protected $fillable = [
        'location_id',
        'shopify_packaging_item_id',
        'available',
    ];

    protected $casts = [
        'location_id' => 'integer',
        'shopify_packaging_item_id' => 'integer',
        'available' => 'integer',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(ShopifyWarehouseLocation::class, 'location_id');
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(ShopifyPackagingItem::class, 'shopify_packaging_item_id');
    }
}
