<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleOrderFeeLine extends Model
{
    public const TYPE_WHOLESALE_FULFILLMENT = 'wholesale_fulfillment';

    public const TYPE_MASTER_CARTON = 'master_carton';

    public const TYPE_PER_ITEM = 'per_item';

    public const TYPE_PALLET_PREP = 'pallet_prep';

    public const TYPE_LTL_PICKUP = 'ltl_pickup';

    public const TYPE_BARCODE_LABELING = 'barcode_labeling';

    public const TYPE_BOX = 'box';

    public const TYPES = [
        self::TYPE_WHOLESALE_FULFILLMENT,
        self::TYPE_MASTER_CARTON,
        self::TYPE_PER_ITEM,
        self::TYPE_PALLET_PREP,
        self::TYPE_LTL_PICKUP,
        self::TYPE_BARCODE_LABELING,
        self::TYPE_BOX,
    ];

    protected $fillable = [
        'wholesale_order_id',
        'line_type',
        'name',
        'quantity',
        'unit_price_cents',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_price_cents' => 'integer',
    ];

    public function wholesaleOrder(): BelongsTo
    {
        return $this->belongsTo(WholesaleOrder::class, 'wholesale_order_id');
    }

    public function lineTotalCents(): int
    {
        return (int) round(((float) $this->quantity) * (int) $this->unit_price_cents);
    }
}
