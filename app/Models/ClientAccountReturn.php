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

    public const RETURN_TYPES = [
        self::TYPE_DIRECT,
        self::TYPE_AMAZON,
        self::TYPE_NORDSTROM,
    ];

    protected $fillable = [
        'client_account_id',
        'rma_number',
        'status',
        'return_type',
        'shiphero_order_id',
        'order_number',
        'customer_name',
        'items_count',
        'warehouse_private_note',
        'processed_at',
    ];

    protected $casts = [
        'items_count' => 'integer',
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
}
