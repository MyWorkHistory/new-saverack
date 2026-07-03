<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleOrder extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_SHIPPED,
    ];

    public const TYPE_AMAZON = 'amazon';

    public const TYPE_TIKTOK = 'tiktok';

    public const TYPE_WALMART = 'walmart';

    public const TYPE_B2B = 'b2b';

    public const TYPE_OTHER = 'other';

    public const ORDER_TYPES = [
        self::TYPE_AMAZON,
        self::TYPE_TIKTOK,
        self::TYPE_WALMART,
        self::TYPE_B2B,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'client_account_id',
        'order_number',
        'order_type',
        'status',
        'instructions',
        'items_count',
        'created_by_user_id',
    ];

    protected $casts = [
        'items_count' => 'integer',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WholesaleOrderLine::class, 'wholesale_order_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WholesaleOrderComment::class, 'wholesale_order_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function isEditable(): bool
    {
        return $this->status !== self::STATUS_SHIPPED;
    }
}
