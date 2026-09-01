<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyOrder extends Model
{
    protected $table = 'shopify_orders';

    protected $fillable = [
        'connection_id',
        'shopify_order_id',
        'name',
        'email',
        'financial_status',
        'fulfillment_status',
        'currency',
        'total_price',
        'shopify_created_at',
        'shopify_updated_at',
        'cancelled_at',
        'crm_hold_reasons',
        'customer_json',
        'shipping_address_json',
        'payload_hash',
        'raw_json',
    ];

    protected $casts = [
        'connection_id' => 'integer',
        'total_price' => 'decimal:2',
        'shopify_created_at' => 'datetime',
        'shopify_updated_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'crm_hold_reasons' => 'array',
        'customer_json' => 'array',
        'shipping_address_json' => 'array',
        'raw_json' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ClientAccountShopifyConnection::class, 'connection_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(ShopifyOrderLineItem::class, 'shopify_order_id');
    }

    public function fulfillmentOrders(): HasMany
    {
        return $this->hasMany(ShopifyFulfillmentOrder::class, 'shopify_order_id');
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(ShopifyFulfillment::class, 'shopify_order_id');
    }
}
