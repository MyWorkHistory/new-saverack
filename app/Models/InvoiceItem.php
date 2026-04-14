<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'sort_order',
        'category',
        'subtype',
        'group_key',
        'description',
        'display_name',
        'sku',
        'service_code',
        'quantity',
        'unit',
        'unit_price_cents',
        'line_total_cents',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
