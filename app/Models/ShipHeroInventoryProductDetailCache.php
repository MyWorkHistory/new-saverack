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
        'parent_kits_json',
        'kit_components_json',
        'allocated_orders_json',
        'backorder_orders_json',
        'synced_at',
        'product_synced_at',
        'parent_kits_synced_at',
        'kit_components_synced_at',
        'allocated_orders_synced_at',
        'backorder_orders_synced_at',
    ];

    protected $casts = [
        'client_account_id' => 'integer',
        'product_json' => 'array',
        'parent_kits_json' => 'array',
        'kit_components_json' => 'array',
        'allocated_orders_json' => 'array',
        'backorder_orders_json' => 'array',
        'synced_at' => 'datetime',
        'product_synced_at' => 'datetime',
        'parent_kits_synced_at' => 'datetime',
        'kit_components_synced_at' => 'datetime',
        'allocated_orders_synced_at' => 'datetime',
        'backorder_orders_synced_at' => 'datetime',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }
}
