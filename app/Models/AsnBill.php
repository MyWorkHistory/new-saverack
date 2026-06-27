<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AsnBill extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_INVOICED = 'invoiced';

    public const FIRST_BILL_NUMBER = 1001;

    public const LINE_RECEIVING_PER_BOX = 'receiving_per_box';

    public const LINE_RECEIVING_PER_PALLET = 'receiving_per_pallet';

    public const LINE_RECEIVING_PER_ITEM = 'receiving_per_item';

    public const LINE_CUSTOM_HOURLY_WORK = 'custom_hourly_work';

    public const LINE_NON_COMPLIANT = 'non_compliant';

    protected $fillable = [
        'bill_number',
        'status',
        'client_account_id',
        'client_account_asn_id',
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

    public function clientAccountAsn(): BelongsTo
    {
        return $this->belongsTo(ClientAccountAsn::class, 'client_account_asn_id');
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
        return $this->hasMany(AsnBillItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AsnBillHistory::class)->orderByDesc('id');
    }

    public function asn(): HasOne
    {
        return $this->hasOne(ClientAccountAsn::class, 'asn_bill_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
