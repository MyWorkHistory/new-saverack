<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipHeroOrderQueueIndex extends Model
{
    public const KIND_AWAITING = 'awaiting';

    public const KIND_ON_HOLD = 'on_hold';

    public const KIND_BACKORDER = 'backorder';

    public const KIND_SHIPPED = 'shipped';

    /** @var list<string> */
    public const QUEUE_KINDS = [
        self::KIND_AWAITING,
        self::KIND_ON_HOLD,
        self::KIND_BACKORDER,
        self::KIND_SHIPPED,
    ];

    protected $table = 'shiphero_order_queue_index';

    protected $fillable = [
        'client_account_id',
        'shiphero_order_id',
        'queue_kind',
        'hold_reason',
        'ready_to_ship',
        'has_backorder',
        'order_number',
        'order_number_search',
        'recipient_name',
        'order_date',
        'ship_date',
        'country',
        'display_status',
        'list_payload',
        'indexed_at',
        'last_seen_at',
    ];

    protected $casts = [
        'client_account_id' => 'integer',
        'ready_to_ship' => 'boolean',
        'has_backorder' => 'boolean',
        'list_payload' => 'array',
        'order_date' => 'datetime',
        'ship_date' => 'datetime',
        'indexed_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
