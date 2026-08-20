<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleBillItem extends Model
{
    protected $fillable = [
        'wholesale_bill_id',
        'line_type',
        'source',
        'client_account_fee_id',
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

    public function wholesaleBill(): BelongsTo
    {
        return $this->belongsTo(WholesaleBill::class);
    }
}
