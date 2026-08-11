<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyFulfillmentOrder extends Model
{
    protected $table = 'shopify_fulfillment_orders';

    protected $fillable = [
        'connection_id',
        'shopify_order_id',
        'shopify_fulfillment_order_id',
        'status',
        'request_status',
        'shopify_location_id',
        'raw_json',
    ];

    protected $casts = [
        'connection_id' => 'integer',
        'shopify_order_id' => 'integer',
        'raw_json' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ClientAccountShopifyConnection::class, 'connection_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrder::class, 'shopify_order_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(ShopifyFulfillmentOrderLineItem::class, 'shopify_fulfillment_order_id');
    }
}
