<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LtlShipment extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUOTED = 'quoted';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const DIRECTION_TO_SAVE_RACK = 'ship_to_save_rack';

    public const DIRECTION_FROM_SAVE_RACK = 'ship_from_save_rack';

    public const TIME_ASAP = 'asap';

    public const TIME_SPECIFIC = 'specific';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_QUOTED,
        self::STATUS_SCHEDULED,
        self::STATUS_IN_TRANSIT,
    ];

    /** @var list<string> */
    public const DIRECTIONS = [
        self::DIRECTION_TO_SAVE_RACK,
        self::DIRECTION_FROM_SAVE_RACK,
    ];

    protected $fillable = [
        'client_account_id',
        'number',
        'status',
        'direction',
        'company_name',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'zip',
        'country',
        'contact_name',
        'contact_email',
        'contact_phone',
        'time_mode',
        'time_from',
        'time_to',
        'load_requirement',
        'pickup_type',
        'notes',
        'quote_amount_cents',
        'quote_carrier',
        'quote_transit_time',
        'quote_service',
        'tracking_number',
        'created_by_user_id',
    ];

    protected $casts = [
        'time_from' => 'datetime',
        'time_to' => 'datetime',
        'quote_amount_cents' => 'integer',
        'client_account_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function pallets(): HasMany
    {
        return $this->hasMany(LtlShipmentPallet::class, 'ltl_shipment_id')->orderBy('sort_order')->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function addressCardTitle(): string
    {
        return $this->direction === self::DIRECTION_FROM_SAVE_RACK
            ? 'Delivery Address'
            : 'Pick Up Address';
    }

    public function directionLabel(): string
    {
        $map = config('ltl.directions', []);

        return (string) ($map[$this->direction] ?? $this->direction);
    }

    public function statusLabel(): string
    {
        $map = config('ltl.statuses', []);

        return (string) ($map[$this->status] ?? $this->status);
    }

    public function destinationLabel(): string
    {
        if ($this->direction === self::DIRECTION_TO_SAVE_RACK) {
            return 'Save Rack';
        }

        $company = trim((string) $this->company_name);

        return $company !== '' ? $company : '—';
    }
}
