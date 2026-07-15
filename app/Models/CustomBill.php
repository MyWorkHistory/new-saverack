<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomBill extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_INVOICED = 'invoiced';

    public const FIRST_BILL_NUMBER = 1001;

    protected $fillable = [
        'bill_number',
        'name',
        'status',
        'client_account_id',
        'bill_date',
        'total_cents',
        'invoice_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'total_cents' => 'integer',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomBillItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(CustomBillHistory::class)->orderByDesc('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isInvoiced(): bool
    {
        return $this->status === self::STATUS_INVOICED;
    }
}
