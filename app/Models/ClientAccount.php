<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'legacy_customer_id',
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
        'slack_channel',
        'in_house_slack',
        'street',
        'city',
        'state',
        'zip',
        'country',
        'notes',
        'account_manager_id',
        'contract_date',
    ];

    protected $casts = [
        'notify_email' => 'boolean',
        'contract_date' => 'date',
    ];

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    /** All logins for this 3PL account (primary admin + secondary users). */
    public function accountUsers(): HasMany
    {
        return $this->hasMany(User::class, 'client_account_id');
    }

    /** Main account admin login (one per account). */
    public function primaryAccountUser(): HasOne
    {
        return $this->hasOne(User::class, 'client_account_id')
            ->where('is_account_primary', true);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(ClientStore::class, 'client_account_id');
    }

    /** CRM notes / activity comments (attachments supported). */
    public function comments(): HasMany
    {
        return $this->hasMany(ClientAccountComment::class, 'client_account_id');
    }

    /** Fulfillment / returns / storage line items for the Account fees tab. */
    public function feeItems(): HasMany
    {
        return $this->hasMany(ClientAccountFee::class, 'client_account_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'client_account_id');
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
