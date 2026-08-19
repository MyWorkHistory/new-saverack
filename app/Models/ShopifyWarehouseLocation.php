<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyWarehouseLocation extends Model
{
    public const TYPES = [
        'Large Bin',
        'Medium Bin',
        'Small Bin',
        'Large Pallet',
        'Medium Pallet',
        'Small Pallet',
    ];

    protected $table = 'shopify_warehouse_locations';

    protected $fillable = [
        'name',
        'type',
        'pickable',
        'sellable',
        'active',
    ];

    protected $casts = [
        'pickable' => 'boolean',
        'sellable' => 'boolean',
        'active' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ShopifyWarehouseLocationItem::class, 'location_id');
    }
}
