<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyProduct extends Model
{
    protected $table = 'shopify_products';

    protected $fillable = [
        'connection_id',
        'shopify_product_id',
        'title',
        'handle',
        'status',
        'vendor',
        'product_type',
        'crm_locked_at',
        'shopify_updated_at',
        'raw_json',
    ];

    protected $casts = [
        'connection_id' => 'integer',
        'crm_locked_at' => 'datetime',
        'shopify_updated_at' => 'datetime',
        'raw_json' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ClientAccountShopifyConnection::class, 'connection_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ShopifyProductVariant::class, 'shopify_product_id');
    }

    public function isCrmLocked(): bool
    {
        return $this->crm_locked_at !== null;
    }
}
