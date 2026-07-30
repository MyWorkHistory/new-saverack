<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LtlShipmentComment extends Model
{
    protected $fillable = [
        'ltl_shipment_id',
        'user_id',
        'body',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(LtlShipment::class, 'ltl_shipment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
