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

    public const SHIPPING_LABELS_CLIENT_PROVIDES = 'client_provides';

    public const SHIPPING_LABELS_SAVE_RACK_PROVIDES = 'save_rack_provides';

    public const SHIPPING_LABELS_PROVIDERS = [
        self::SHIPPING_LABELS_CLIENT_PROVIDES,
        self::SHIPPING_LABELS_SAVE_RACK_PROVIDES,
    ];

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
        'shiphero_order_id',
        'shipping_address',
        'shipping_carrier',
        'shipping_method',
        'shipping_labels_provider',
        'shipping_labels_comment',
        'shipping_label_path',
        'shipping_label_original_name',
        'shipping_label_mime',
        'sku_barcode_labels',
        'sku_barcode_labels_comment',
        'cover_existing_barcodes',
        'cover_existing_barcodes_comment',
        'individual_sku_packaging',
        'individual_sku_packaging_comment',
        'bundle_configuration',
        'bundle_configuration_comment',
        'shipping_method_requirement',
        'shipping_method_requirement_comment',
        'master_cartons',
        'master_cartons_comment',
        'items_count',
        'boxes_saved_at',
        'pallets_saved_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'items_count' => 'integer',
        'shipping_address' => 'array',
        'boxes_saved_at' => 'datetime',
        'pallets_saved_at' => 'datetime',
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

    public function shippingLabels(): HasMany
    {
        return $this->hasMany(WholesaleOrderShippingLabel::class, 'wholesale_order_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(WholesaleOrderPackage::class, 'wholesale_order_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function boxes(): HasMany
    {
        return $this->hasMany(WholesaleOrderPackage::class, 'wholesale_order_id')
            ->where('package_type', WholesaleOrderPackage::TYPE_BOX)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function pallets(): HasMany
    {
        return $this->hasMany(WholesaleOrderPackage::class, 'wholesale_order_id')
            ->where('package_type', WholesaleOrderPackage::TYPE_PALLET)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING], true);
    }

    public function canEditLines(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
        ], true);
    }

    public function hasCompleteShippingAddress(): bool
    {
        $ship = is_array($this->shipping_address) ? $this->shipping_address : [];
        foreach (['first_name', 'last_name', 'address1', 'city', 'state', 'zip', 'country'] as $field) {
            if (trim((string) ($ship[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    public function hasUploadedShippingLabel(): bool
    {
        $this->loadMissing('shippingLabels');
        if ($this->shippingLabels->isNotEmpty()) {
            return true;
        }

        return trim((string) ($this->shipping_label_path ?? '')) !== '';
    }

    public static function shippingLabelsProviderLabel(?string $provider): ?string
    {
        if ($provider === null || trim($provider) === '') {
            return null;
        }

        $labels = config('wholesale_orders.shipping_labels_provider', []);

        return $labels[$provider] ?? null;
    }

    public function hasShippingLabelsResolved(): bool
    {
        $provider = trim((string) ($this->shipping_labels_provider ?? ''));
        if ($provider === self::SHIPPING_LABELS_CLIENT_PROVIDES) {
            // Provider selected is enough; uploaded files are optional.
            return true;
        }
        if ($provider === self::SHIPPING_LABELS_SAVE_RACK_PROVIDES) {
            return $this->hasCompleteShippingAddress() && $this->hasShippingCarrierAndMethod();
        }

        return false;
    }

    public function hasShippingCarrierAndMethod(): bool
    {
        $carrier = trim((string) ($this->shipping_carrier ?? ''));
        $method = trim((string) ($this->shipping_method ?? ''));

        return $carrier !== '' && $method !== '' && strcasecmp($method, 'Select') !== 0;
    }

    public function hasRequirementsFilled(): bool
    {
        return trim((string) ($this->sku_barcode_labels ?? '')) !== ''
            && trim((string) ($this->cover_existing_barcodes ?? '')) !== ''
            && trim((string) ($this->individual_sku_packaging ?? '')) !== ''
            && trim((string) ($this->bundle_configuration ?? '')) !== ''
            && trim((string) ($this->shipping_method_requirement ?? '')) !== ''
            && trim((string) ($this->master_cartons ?? '')) !== '';
    }

    public function hasAllLinesBarcodeResolved(): bool
    {
        $this->loadMissing('lines');
        if ($this->lines->isEmpty()) {
            return false;
        }

        return $this->lines->every(fn (WholesaleOrderLine $line) => $line->isBarcodeResolved());
    }

    public function isReadyToShipEligible(): bool
    {
        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING], true)) {
            return false;
        }
        if ($this->shiphero_order_id) {
            return false;
        }

        $this->loadMissing('lines');
        if ($this->lines->isEmpty()) {
            return false;
        }

        return $this->hasShippingLabelsResolved()
            && $this->hasRequirementsFilled()
            && $this->hasAllLinesBarcodeResolved();
    }

    public function isFullyPicked(): bool
    {
        $this->loadMissing('lines');
        if ($this->lines->isEmpty()) {
            return false;
        }

        return $this->lines->every(fn (WholesaleOrderLine $line) => $line->isFullyPicked());
    }

    public function canMarkPicked(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS && $this->isFullyPicked();
    }
}
