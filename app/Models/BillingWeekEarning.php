<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingWeekEarning extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'week_start',
        'week_end',
        'fulfillment_cents',
        'postage_cents',
        'materials_cents',
        'returns_cents',
        'custom_work_cents',
        'wholesale_cents',
        'total_cents',
        'matched_line_count',
        'unmatched_count',
        'status',
        'error_message',
        'generated_at',
        'generated_by_user_id',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'fulfillment_cents' => 'integer',
        'postage_cents' => 'integer',
        'materials_cents' => 'integer',
        'returns_cents' => 'integer',
        'custom_work_cents' => 'integer',
        'wholesale_cents' => 'integer',
        'total_cents' => 'integer',
        'matched_line_count' => 'integer',
        'unmatched_count' => 'integer',
        'generated_at' => 'datetime',
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function unmatchedItems(): HasMany
    {
        return $this->hasMany(BillingWeekEarningUnmatchedItem::class, 'billing_week_earning_id');
    }
}
