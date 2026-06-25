<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipHeroInventoryProductIndex extends Model
{
    protected $table = 'shiphero_inventory_product_index';

    protected $fillable = [
        'client_account_id',
        'shiphero_customer_account_id',
        'shiphero_product_id',
        'sku',
        'sku_search',
        'name',
        'name_search',
        'barcode',
        'barcode_search',
        'image_url',
        'product_active',
        'kit',
        'kit_build',
        'warehouse_id',
        'warehouse_active',
        'on_hand',
        'allocated',
        'backorder',
        'synced_at',
        'last_seen_at',
    ];

    protected $casts = [
        'client_account_id' => 'integer',
        'product_active' => 'boolean',
        'kit' => 'boolean',
        'kit_build' => 'boolean',
        'warehouse_active' => 'boolean',
        'on_hand' => 'float',
        'allocated' => 'float',
        'backorder' => 'float',
        'synced_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
