<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleOrderPackage extends Model
{
    public const TYPE_BOX = 'box';

    public const TYPE_PALLET = 'pallet';

    public const TYPES = [
        self::TYPE_BOX,
        self::TYPE_PALLET,
    ];

    protected $fillable = [
        'wholesale_order_id',
        'package_type',
        'width',
        'length',
        'height',
        'weight',
        'sort_order',
    ];

    protected $casts = [
        'width' => 'float',
        'length' => 'float',
        'height' => 'float',
        'weight' => 'float',
        'sort_order' => 'integer',
    ];

    public function wholesaleOrder(): BelongsTo
    {
        return $this->belongsTo(WholesaleOrder::class, 'wholesale_order_id');
    }
}
