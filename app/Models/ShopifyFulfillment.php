<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyFulfillment extends Model
{
    protected $table = 'shopify_fulfillments';

    protected $fillable = [
        'connection_id',
        'shopify_order_id',
        'shopify_fulfillment_id',
        'status',
        'tracking_company',
        'tracking_number',
        'line_items_json',
        'created_by_user_id',
        'raw_json',
    ];

    protected $casts = [
        'connection_id' => 'integer',
        'shopify_order_id' => 'integer',
        'created_by_user_id' => 'integer',
        'line_items_json' => 'array',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
