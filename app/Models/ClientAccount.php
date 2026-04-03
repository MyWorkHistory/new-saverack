<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientAccount extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'status',
        'company_name',
        'brand_name',
        'website',
        'contact_first_name',
        'contact_last_name',
        'email',
        'phone',
        'notify_email',
        'telegram_handle',
        'whatsapp_e164',
        'street',
        'city',
        'state',
        'zip',
        'country',
        'account_manager_id',
    ];

    protected $casts = [
        'notify_email' => 'boolean',
    ];

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(ClientStore::class, 'client_account_id');
    }

    public function contactFullName(): string
    {
        $parts = array_filter([
            $this->contact_first_name,
            $this->contact_last_name,
        ], static function ($s) {
            return $s !== null && $s !== '';
        });

        return trim(implode(' ', $parts));
    }
}
