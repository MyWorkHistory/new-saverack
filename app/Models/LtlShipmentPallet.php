<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LtlShipmentPallet extends Model
{
    protected $fillable = [
        'ltl_shipment_id',
        'sort_order',
        'commodity',
        'length_in',
        'width_in',
        'height_in',
        'weight_lbs',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'length_in' => 'float',
        'width_in' => 'float',
        'height_in' => 'float',
        'weight_lbs' => 'float',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(LtlShipment::class, 'ltl_shipment_id');
    }
}
