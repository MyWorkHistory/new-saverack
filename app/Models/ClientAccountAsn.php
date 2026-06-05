<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientAccountAsn extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NON_COMPLIANT = 'non_compliant';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_NON_COMPLIANT,
    ];

    /** Portal users may delete ASNs in these statuses. */
    public const DELETABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
    ];

    protected $fillable = [
        'client_account_id',
        'asn_number',
        'status',
        'date_received',
        'processed_at',
        'processed_by_user_id',
        'total_boxes',
        'total_pallets',
        'expected_qty',
        'accepted_qty',
        'rejected_qty',
        'warehouse_notes',
    ];

    protected $casts = [
        'date_received' => 'date',
        'processed_at' => 'datetime',
        'total_boxes' => 'integer',
        'total_pallets' => 'integer',
        'expected_qty' => 'integer',
        'accepted_qty' => 'integer',
        'rejected_qty' => 'integer',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClientAccountAsnLine::class, 'client_account_asn_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function trackings(): HasMany
    {
        return $this->hasMany(ClientAccountAsnTracking::class, 'client_account_asn_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function vendorLines(): HasMany
    {
        return $this->hasMany(ClientAccountAsnVendorLine::class, 'client_account_asn_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function customBill(): BelongsTo
    {
        return $this->belongsTo(CustomBill::class, 'custom_bill_id');
    }
}
