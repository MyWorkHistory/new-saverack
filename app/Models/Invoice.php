<?php

namespace App\Models;

use App\Support\Billing\InvoiceLifecycleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAYMENT_FAILED = 'payment_failed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_PARTIAL,
        self::STATUS_PAID,
        self::STATUS_VOID,
        self::STATUS_PROCESSING,
        self::STATUS_PAYMENT_FAILED,
    ];

    protected $fillable = [
        'invoice_number',
        'client_account_id',
        'status',
        'currency',
        'issued_at',
        'due_at',
        'paid_at',
        'billing_period_start',
        'billing_period_end',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'amount_paid_cents',
        'balance_due_cents',
        'tax_rate_basis_points',
        'payment_terms',
        'po_number',
        'customer_notes',
        'internal_notes',
        'created_by_user_id',
        'share_token',
        'share_token_generated_at',
        'manual_total_override_cents',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
        'share_token_generated_at' => 'datetime',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(InvoiceHistory::class)->orderByDesc('created_at');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(InvoiceImport::class);
    }

    /**
     * Business “service period” shown on the invoice (same columns as billing_period_*).
     */
    public function getServicePeriodStartAttribute()
    {
        return $this->billing_period_start;
    }

    public function getServicePeriodEndAttribute()
    {
        return $this->billing_period_end;
    }

    public function isEditableDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function isPaidLike(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_VOID], true);
    }

    /** Past due: open-like status and due date before today (see InvoiceLifecycleStatus). */
    public function isPastDueDerived(): bool
    {
        return InvoiceLifecycleStatus::isPastDue($this);
    }
}
