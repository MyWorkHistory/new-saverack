<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyWebhookEvent extends Model
{
    protected $table = 'shopify_webhook_events';

    protected $fillable = [
        'event_id',
        'topic',
        'shop_domain',
        'connection_id',
        'payload',
        'processed_at',
        'processing_error',
    ];

    protected $casts = [
        'connection_id' => 'integer',
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ClientAccountShopifyConnection::class, 'connection_id');
    }
}
