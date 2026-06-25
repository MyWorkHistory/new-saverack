<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnBillItem extends Model
{
    protected $fillable = [
        'return_bill_id',
        'line_type',
        'name',
        'quantity',
        'unit_price_cents',
        'line_total_cents',
        'metadata',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_price_cents' => 'integer',
        'line_total_cents' => 'integer',
        'metadata' => 'array',
    ];

    public function returnBill(): BelongsTo
    {
        return $this->belongsTo(ReturnBill::class);
    }
}
