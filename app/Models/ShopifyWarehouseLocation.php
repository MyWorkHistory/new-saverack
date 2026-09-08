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

    /**
     * CRM 2.0 inventory adjustment reasons (config/inventory.php).
     *
     * @return list<string>
     */
    public static function addItemReasons(): array
    {
        $reasons = config('inventory.adjustment_reasons', []);
        if (! is_array($reasons)) {
            return [];
        }

        $out = [];
        foreach ($reasons as $reason) {
            $reason = trim((string) $reason);
            if ($reason !== '') {
                $out[] = $reason;
            }
        }

        return array_values(array_unique($out));
    }

    public static function defaultAddItemReason(): string
    {
        $default = trim((string) config('inventory.default_add_location_reason', 'Account Setup'));

        return $default !== '' ? $default : 'Account Setup';
    }

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
