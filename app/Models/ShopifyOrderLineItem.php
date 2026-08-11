<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyOrderLineItem extends Model
{
    protected $table = 'shopify_order_line_items';

    protected $fillable = [
        'connection_id',
        'shopify_order_id',
        'shopify_line_item_id',
        'shopify_variant_id',
        'shopify_product_id',
        'sku',
        'title',
        'variant_title',
        'quantity',
        'fulfillable_quantity',
        'fulfilled_quantity',
        'price',
        'raw_json',
    ];

    protected $casts = [
        'connection_id' => 'integer',
        'shopify_order_id' => 'integer',
        'quantity' => 'integer',
        'fulfillable_quantity' => 'integer',
        'fulfilled_quantity' => 'integer',
        'price' => 'decimal:2',
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
}
