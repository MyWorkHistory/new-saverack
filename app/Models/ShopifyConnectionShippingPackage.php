<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyConnectionShippingPackage extends Model
{
    protected $table = 'shopify_connection_shipping_packages';

    protected $fillable = [
        'connection_id',
        'shopify_packaging_item_id',
        'shopify_shipping_package_id',
    ];

    protected $casts = [
        'connection_id' => 'integer',
        'shopify_packaging_item_id' => 'integer',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ClientAccountShopifyConnection::class, 'connection_id');
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(ShopifyPackagingItem::class, 'shopify_packaging_item_id');
    }
}
