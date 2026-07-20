<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderDashboardSection extends Model
{
    public const STATUS_IDLE = 'idle';

    public const STATUS_RUNNING = 'running';

    public const STATUS_FAILED = 'failed';

    public const KEY_READY_TO_SHIP = 'ready_to_ship';

    public const KEY_SHIPPED = 'shipped';

    public const KEY_ON_HOLD = 'on_hold';

    public const KEY_HOLD_OPERATOR = 'hold_operator';

    public const KEY_HOLD_ADDRESS = 'hold_address';

    public const KEY_HOLD_FRAUD = 'hold_fraud';

    public const KEY_HOLD_PAYMENT = 'hold_payment';

    public const KEY_HOLD_USER = 'hold_user';

    public const KEY_HOLD_BACKORDER = 'hold_backorder';

    public const KEY_ASN_PENDING = 'asn_pending';

    /** @var list<string> */
    public const PRIMARY_PILL_KEYS = [
        self::KEY_READY_TO_SHIP,
        self::KEY_SHIPPED,
        self::KEY_ON_HOLD,
    ];

    /** @var list<string> */
    public const ALL_KEYS = [
        self::KEY_ASN_PENDING,
        self::KEY_READY_TO_SHIP,
        self::KEY_SHIPPED,
        self::KEY_ON_HOLD,
        self::KEY_HOLD_OPERATOR,
        self::KEY_HOLD_ADDRESS,
        self::KEY_HOLD_FRAUD,
        self::KEY_HOLD_PAYMENT,
        self::KEY_HOLD_USER,
        self::KEY_HOLD_BACKORDER,
    ];

    /** @var list<string> */
    public const HOLD_KEYS = [
        self::KEY_HOLD_OPERATOR,
        self::KEY_HOLD_ADDRESS,
        self::KEY_HOLD_FRAUD,
        self::KEY_HOLD_PAYMENT,
        self::KEY_HOLD_USER,
        self::KEY_HOLD_BACKORDER,
    ];

    /**
     * Sections that should include paused accounts in account breakdowns / paused order counts.
     *
     * @return list<string>
     */
    public static function keysIncludingPausedAccounts(): array
    {
        return array_merge([self::KEY_ON_HOLD], self::HOLD_KEYS);
    }

    public static function includesPausedAccounts(string $sectionKey): bool
    {
        return in_array($sectionKey, self::keysIncludingPausedAccounts(), true);
    }

    /** @var list<string> */
    public const SHIPHERO_KEYS = [
        self::KEY_READY_TO_SHIP,
        self::KEY_SHIPPED,
        self::KEY_ON_HOLD,
        self::KEY_HOLD_OPERATOR,
        self::KEY_HOLD_ADDRESS,
        self::KEY_HOLD_FRAUD,
        self::KEY_HOLD_PAYMENT,
        self::KEY_HOLD_USER,
        self::KEY_HOLD_BACKORDER,
    ];

    protected $primaryKey = 'section_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'section_key',
        'payload',
        'total_count',
        'status',
        'refreshed_at',
        'refresh_started_at',
        'error_message',
        'duration_ms',
    ];

    protected $casts = [
        'payload' => 'array',
        'total_count' => 'integer',
        'refreshed_at' => 'datetime',
        'refresh_started_at' => 'datetime',
        'duration_ms' => 'integer',
    ];
}
