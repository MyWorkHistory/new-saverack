<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyOrderLine extends Model
{
    protected $fillable = [
        'supply_order_id',
        'supply_id',
        'name',
        'type',
        'link',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SupplyOrder::class, 'supply_order_id');
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }
}
