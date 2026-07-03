<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientAccountReturn extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_RECEIVED,
        self::STATUS_COMPLETED,
    ];

    public const LIST_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RECEIVED,
        self::STATUS_COMPLETED,
    ];

    public const TYPE_DIRECT = 'direct';

    public const TYPE_AMAZON = 'amazon';

    public const TYPE_NORDSTROM = 'nordstrom';

    public const SOURCE_PORTAL = 'portal';

    public const SOURCE_ADMIN = 'admin';

    public const UNKNOWN_SKU = 'Unknown SKU';

    public const RETURN_TYPES = [
        self::TYPE_DIRECT,
        self::TYPE_AMAZON,
        self::TYPE_NORDSTROM,
    ];

    protected $fillable = [
        'client_account_id',
        'rma_number',
        'status',
        'created_source',
        'return_type',
        'shiphero_order_id',
        'order_number',
        'customer_name',
        'items_count',
        'warehouse_private_note',
        'return_fee_first_item',
        'return_fee_additional_item',
        'return_fee_non_compliant',
        'is_non_compliant',
        'non_compliant_reason',
        'non_compliant_declared_items',
        'fees_locked_at',
        'return_bill_id',
        'processed_at',
        'processed_by_user_id',
    ];

    protected $casts = [
        'items_count' => 'integer',
        'is_non_compliant' => 'boolean',
        'non_compliant_declared_items' => 'integer',
        'return_fee_first_item' => 'decimal:4',
        'return_fee_additional_item' => 'decimal:4',
        'return_fee_non_compliant' => 'decimal:4',
        'fees_locked_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClientAccountReturnLine::class, 'client_account_return_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function returnBill(): BelongsTo
    {
        return $this->belongsTo(ReturnBill::class, 'return_bill_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    public function feesAreLocked(): bool
    {
        return $this->fees_locked_at !== null;
    }

    public function isAdminCreated(): bool
    {
        return $this->created_source === self::SOURCE_ADMIN;
    }

    public function isNonCompliant(): bool
    {
        return (bool) $this->is_non_compliant;
    }
}
