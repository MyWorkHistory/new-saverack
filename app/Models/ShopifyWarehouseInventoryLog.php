<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyWarehouseInventoryLog extends Model
{
    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_LABEL_TRANSFER = 'Transfer';

    protected $table = 'shopify_warehouse_inventory_logs';

    protected $fillable = [
        'shopify_variant_id',
        'location_id',
        'location_name',
        'user_id',
        'type',
        'type_label',
        'quantity_delta',
        'old_on_hand',
        'new_on_hand',
        'note',
        'transfer_group',
    ];

    protected $casts = [
        'shopify_variant_id' => 'integer',
        'location_id' => 'integer',
        'user_id' => 'integer',
        'quantity_delta' => 'integer',
        'old_on_hand' => 'integer',
        'new_on_hand' => 'integer',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ShopifyProductVariant::class, 'shopify_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ShopifyWarehouseLocation::class, 'location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function directionLabel(): string
    {
        if ($this->type === self::TYPE_TRANSFER_IN) {
            return 'Transfer in';
        }
        if ($this->type === self::TYPE_TRANSFER_OUT) {
            return 'Transfer out';
        }

        return (string) $this->type_label;
    }
}
