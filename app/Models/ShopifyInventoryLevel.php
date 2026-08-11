<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyInventoryLevel extends Model
{
    protected $table = 'shopify_inventory_levels';

    protected $fillable = [
        'connection_id',
        'shopify_inventory_item_id',
        'shopify_location_id',
        'available',
        'shopify_updated_at',
    ];

    protected $casts = [
        'connection_id' => 'integer',
        'available' => 'integer',
        'shopify_updated_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ClientAccountShopifyConnection::class, 'connection_id');
    }
}
