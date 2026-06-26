<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReturnBill extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_INVOICED = 'invoiced';

    public const FIRST_BILL_NUMBER = 1001;

    public const LINE_FIRST_ITEM = 'first_item';

    public const LINE_ADDITIONAL_ITEMS = 'additional_items';

    public const LINE_ASSEMBLY = 'assembly';

    public const LINE_REPACKAGING = 'repackaging';

    public const LINE_DISPOSAL = 'disposal';

    protected $fillable = [
        'bill_number',
        'status',
        'client_account_id',
        'client_account_return_id',
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

    public function clientAccountReturn(): BelongsTo
    {
        return $this->belongsTo(ClientAccountReturn::class, 'client_account_return_id');
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
        return $this->hasMany(ReturnBillItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ReturnBillHistory::class)->orderByDesc('id');
    }

    public function returnRecord(): HasOne
    {
        return $this->hasOne(ClientAccountReturn::class, 'return_bill_id');
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
