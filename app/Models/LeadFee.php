<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadFee extends Model
{
    protected $fillable = [
        'lead_id',
        'pricing_template_id',
        'fee_group',
        'line_code',
        'label',
        'description',
        'icon_path',
        'amount',
        'cost',
        'currency',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'cost' => 'decimal:4',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function pricingTemplate(): BelongsTo
    {
        return $this->belongsTo(PricingFeeTemplate::class, 'pricing_template_id');
    }
}
