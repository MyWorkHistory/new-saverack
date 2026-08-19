<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyProductVariant extends Model
{
    protected $table = 'shopify_product_variants';

    protected $fillable = [
        'connection_id',
        'shopify_product_id',
        'shopify_variant_id',
        'shopify_inventory_item_id',
        'title',
        'sku',
        'barcode',
        'price',
        'weight',
        'weight_unit',
        'length',
        'width',
        'height',
        'dimension_unit',
        'requires_shipping',
        'crm_locked_at',
        'shopify_updated_at',
        'raw_json',
    ];

    protected $casts = [
        'connection_id' => 'integer',
        'shopify_product_id' => 'integer',
        'price' => 'decimal:2',
        'weight' => 'decimal:4',
        'length' => 'decimal:4',
        'width' => 'decimal:4',
        'height' => 'decimal:4',
        'requires_shipping' => 'boolean',
        'crm_locked_at' => 'datetime',
        'shopify_updated_at' => 'datetime',
        'raw_json' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ClientAccountShopifyConnection::class, 'connection_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopifyProduct::class, 'shopify_product_id');
    }

    public function isCrmLocked(): bool
    {
        return $this->crm_locked_at !== null;
    }
}
