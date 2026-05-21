<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipHeroInventoryProductDetailCache extends Model
{
    protected $table = 'shiphero_inventory_product_detail_cache';

    protected $fillable = [
        'client_account_id',
        'sku',
        'sku_search',
        'product_json',
        'allocated_orders_json',
        'backorder_orders_json',
        'synced_at',
    ];

    protected $casts = [
        'client_account_id' => 'integer',
        'product_json' => 'array',
        'allocated_orders_json' => 'array',
        'backorder_orders_json' => 'array',
        'synced_at' => 'datetime',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }
}
