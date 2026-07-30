<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingWeekEarningUnmatchedItem extends Model
{
    public const REASON_FEE_NOT_FOUND = 'fee_not_found';

    public const REASON_COST_MISSING = 'cost_missing';

    protected $fillable = [
        'billing_week_earning_id',
        'client_account_id',
        'invoice_id',
        'invoice_item_id',
        'category',
        'display_name',
        'quantity',
        'billed_cents',
        'reason',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'billed_cents' => 'integer',
        'invoice_item_id' => 'integer',
    ];

    public function earning(): BelongsTo
    {
        return $this->belongsTo(BillingWeekEarning::class, 'billing_week_earning_id');
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
