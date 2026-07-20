<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingWeekSummary extends Model
{
    protected $fillable = [
        'week_start',
        'week_end',
        'total_billed_cents',
        'fulfillment_cents',
        'postage_cents',
        'materials_cents',
        'returns_cents',
        'custom_work_cents',
        'wholesale_cents',
        'invoice_count',
        'generated_at',
        'generated_by_user_id',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'total_billed_cents' => 'integer',
        'fulfillment_cents' => 'integer',
        'postage_cents' => 'integer',
        'materials_cents' => 'integer',
        'returns_cents' => 'integer',
        'custom_work_cents' => 'integer',
        'wholesale_cents' => 'integer',
        'invoice_count' => 'integer',
        'generated_at' => 'datetime',
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
