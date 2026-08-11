<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyLocation extends Model
{
    protected $table = 'shopify_locations';

    protected $fillable = [
        'connection_id',
        'shopify_location_id',
        'name',
        'active',
        'legacy',
        'address_json',
    ];

    protected $casts = [
        'connection_id' => 'integer',
        'active' => 'boolean',
        'legacy' => 'boolean',
        'address_json' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ClientAccountShopifyConnection::class, 'connection_id');
    }
}
