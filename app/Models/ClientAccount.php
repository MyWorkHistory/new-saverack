<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

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

    public const PAUSE_REASON_ACCOUNT_PAST_DUE = 'account_past_due';

    public const PAUSE_REASON_ADMIN = 'admin';

    public const PAUSE_REASON_USER_REQUEST = 'user_request';

    public const PAUSE_REASONS = [
        self::PAUSE_REASON_ACCOUNT_PAST_DUE,
        self::PAUSE_REASON_ADMIN,
        self::PAUSE_REASON_USER_REQUEST,
    ];

    public const FULFILLMENT_PRICING_STATUS_PENDING = 'pending';

    public const FULFILLMENT_PRICING_STATUS_APPROVED = 'approved';

    public const FULFILLMENT_PRICING_STATUSES = [
        self::FULFILLMENT_PRICING_STATUS_PENDING,
        self::FULFILLMENT_PRICING_STATUS_APPROVED,
    ];

    public const PAUSE_REASON_LABELS = [
        self::PAUSE_REASON_ACCOUNT_PAST_DUE => 'Account Past Due',
        self::PAUSE_REASON_ADMIN => 'Admin',
        self::PAUSE_REASON_USER_REQUEST => 'User Request',
    ];

    public const INACTIVE_REASON_ACCOUNT_CLOSED = 'account_closed';

    public const INACTIVE_REASON_COLLECTIONS = 'collections';

    public const INACTIVE_REASONS = [
        self::INACTIVE_REASON_ACCOUNT_CLOSED,
        self::INACTIVE_REASON_COLLECTIONS,
    ];

    public const INACTIVE_REASON_LABELS = [
        self::INACTIVE_REASON_ACCOUNT_CLOSED => 'Account Closed',
        self::INACTIVE_REASON_COLLECTIONS => 'Collections',
    ];

    public static function pauseReasonLabel(?string $reason): ?string
    {
        if ($reason === null || trim($reason) === '') {
            return null;
        }

        return self::PAUSE_REASON_LABELS[$reason] ?? null;
    }

    public static function inactiveReasonLabel(?string $reason): ?string
    {
        if ($reason === null || trim($reason) === '') {
            return null;
        }

        return self::INACTIVE_REASON_LABELS[$reason] ?? null;
    }

    public const DEFAULT_PAYMENT_TYPES = [
        'ACH',
        'Wire',
        'Check',
        'Manual',
        'Credit Card',
        'Paypal',
        'Varies',
    ];

    protected $fillable = [
        'legacy_customer_id',
        'status',
        'paused_at',
        'pause_reason',
        'inactive_reason',
        'company_name',
        'brand_name',
        'website',
        'contact_first_name',
        'contact_last_name',
        'email',
        'phone',
        'notify_email',
        'notification_email',
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
        'terms_of_service_body',
        'fulfillment_agreement_accepted_at',
        'fulfillment_agreement_path',
        'fulfillment_agreement_original_name',
        'fulfillment_agreement_mime',
        'fulfillment_agreement_method',
        'fulfillment_agreement_company',
        'fulfillment_agreement_rep_name',
        'fulfillment_agreement_client_signed_at',
        'fulfillment_agreement_client_signature',
        'fulfillment_agreement_staff_rep_name',
        'fulfillment_agreement_staff_signed_at',
        'fulfillment_agreement_staff_signature',
        'fulfillment_pricing_status',
        'fulfillment_pricing_approved_at',
        'fulfillment_pricing_accepted_at',
        'account_manager_id',
        'contract_date',
        'stripe_customer_id',
        'shiphero_customer_account_id',
        'shiphero_client_refresh_token',
        'inventory_catalog_synced_at',
        'inventory_catalog_sync_started_at',
        'inventory_catalog_sync_status',
        'inventory_catalog_product_count',
        'order_queue_synced_at',
        'order_queue_sync_started_at',
        'order_queue_sync_status',
        'whatsapp_api_id',
        'default_payment_type',
        'postage_option',
        'packaging_option',
        'payment_terms_days',
        'onboarding_billing_method',
        'onboarding_billing_status',
        'onboarding_preferences',
        'onboarding_verifications',
        'brand_logo_path',
        'cc_fee_percent',
        'billing_available_funds_cents',
    ];

    protected $casts = [
        'notify_email' => 'boolean',
        'onboarding_preferences' => 'array',
        'onboarding_verifications' => 'array',
        'contract_date' => 'date',
        'paused_at' => 'datetime',
        'fulfillment_agreement_accepted_at' => 'datetime',
        'fulfillment_agreement_client_signed_at' => 'datetime',
        'fulfillment_agreement_staff_signed_at' => 'datetime',
        'fulfillment_pricing_approved_at' => 'datetime',
        'fulfillment_pricing_accepted_at' => 'datetime',
        'cc_fee_percent' => 'decimal:2',
        'billing_available_funds_cents' => 'integer',
        'payment_terms_days' => 'integer',
        'inventory_catalog_synced_at' => 'datetime',
        'inventory_catalog_sync_started_at' => 'datetime',
        'inventory_catalog_product_count' => 'integer',
        'order_queue_synced_at' => 'datetime',
        'order_queue_sync_started_at' => 'datetime',
    ];

    /**
     * Accounts linked to a ShipHero customer id (any CRM status).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithShipHeroCustomerLink($query)
    {
        return $query
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '');
    }

    /**
     * Active accounts used for home / fulfillment order dashboards (RTS, shipped).
     * Inactive, paused, and pending accounts are excluded so those cards match list pages
     * and ShipHero "hide orders from app" for non-active statuses.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOperationalForOrderDashboards($query)
    {
        return $query
            ->withShipHeroCustomerLink()
            ->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Active + paused ShipHero-linked accounts for on-hold dashboards / paused order counts.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActiveOrPausedForOrderDashboards($query)
    {
        return $query
            ->withShipHeroCustomerLink()
            ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_PAUSED]);
    }

    /**
     * Resolve CRM account for a ShipHero customer id.
     * Prefers active, then paused, then any exact match. No fuzzy substring matching.
     */
    public static function resolveByShipHeroCustomerId(string $customerId): ?self
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return null;
        }

        $matches = static::query()
            ->where('shiphero_customer_account_id', $customerId)
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            return null;
        }

        $active = $matches->first(static function (self $account) {
            return $account->status === self::STATUS_ACTIVE;
        });
        if ($active instanceof self) {
            return $active;
        }

        $paused = $matches->first(static function (self $account) {
            return $account->status === self::STATUS_PAUSED;
        });
        if ($paused instanceof self) {
            return $paused;
        }

        return $matches->first();
    }

    protected static function booted(): void
    {
        static::created(static function (ClientAccount $account) {
            $base = Str::slug((string) $account->company_name);
            if ($base === '') {
                $base = 'account';
            }
            $account->invoice_share_slug = $base.'-'.$account->id;
            $account->saveQuietly();
        });

        static::updating(static function (ClientAccount $account) {
            if ($account->isDirty('company_name')) {
                $base = Str::slug((string) $account->company_name);
                if ($base === '') {
                    $base = 'account';
                }
                $account->invoice_share_slug = $base.'-'.$account->id;
            }
        });
    }

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

    public function invoiceImports(): HasMany
    {
        return $this->hasMany(InvoiceImport::class, 'client_account_id');
    }

    public function onDemandProducts(): HasMany
    {
        return $this->hasMany(ClientAccountOnDemandProduct::class, 'client_account_id');
    }

    public function asns(): HasMany
    {
        return $this->hasMany(ClientAccountAsn::class, 'client_account_id')
            ->orderByDesc('id');
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
