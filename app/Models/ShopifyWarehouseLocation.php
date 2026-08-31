<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyWarehouseLocation extends Model
{
    public const TYPES = [
        'Large Bin',
        'Large Pallet',
        'Large Shelf',
        'Medium Bin',
        'Medium Pallet',
        'Medium Shelf',
        'Small Bin',
        'Small Pallet',
        'Small Shelf',
    ];

    public const ADD_ITEM_REASONS = [
        'Account Setup',
        'Client Request',
        'Cycle Count',
        'Expired',
        'Kitting / Bundling',
        'Order Fulfillment',
        'Picking Error',
        'Putaway Error',
        'Receiving Discrepancy',
        'Restock',
        'Return',
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
