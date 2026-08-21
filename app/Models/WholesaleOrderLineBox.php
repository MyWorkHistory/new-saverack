<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleOrderLineBox extends Model
{
    protected $fillable = [
        'wholesale_order_line_id',
        'length',
        'width',
        'height',
        'weight',
        'quantity',
        'sort_order',
    ];

    protected $casts = [
        'length' => 'float',
        'width' => 'float',
        'height' => 'float',
        'weight' => 'float',
        'quantity' => 'integer',
        'sort_order' => 'integer',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(WholesaleOrderLine::class, 'wholesale_order_line_id');
    }
}
