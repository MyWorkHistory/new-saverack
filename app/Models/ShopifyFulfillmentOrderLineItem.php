<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyFulfillmentOrderLineItem extends Model
{
    protected $table = 'shopify_fulfillment_order_line_items';

    protected $fillable = [
        'connection_id',
        'shopify_fulfillment_order_id',
        'shopify_order_line_item_id',
        'shopify_fo_line_item_id',
        'shopify_line_item_id',
        'total_quantity',
        'remaining_quantity',
        'raw_json',
    ];

    protected $casts = [
        'connection_id' => 'integer',
        'shopify_fulfillment_order_id' => 'integer',
        'shopify_order_line_item_id' => 'integer',
        'total_quantity' => 'integer',
        'remaining_quantity' => 'integer',
        'raw_json' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ClientAccountShopifyConnection::class, 'connection_id');
    }

    public function fulfillmentOrder(): BelongsTo
    {
        return $this->belongsTo(ShopifyFulfillmentOrder::class, 'shopify_fulfillment_order_id');
    }

    public function orderLineItem(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrderLineItem::class, 'shopify_order_line_item_id');
    }
}
