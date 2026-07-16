<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleOrderShippingLabel extends Model
{
    protected $fillable = [
        'wholesale_order_id',
        'path',
        'original_name',
        'mime',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function wholesaleOrder(): BelongsTo
    {
        return $this->belongsTo(WholesaleOrder::class, 'wholesale_order_id');
    }
}
