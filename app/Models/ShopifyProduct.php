<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyProduct extends Model
{
    protected $table = 'shopify_products';

    protected $fillable = [
        'connection_id',
        'shopify_product_id',
        'title',
        'handle',
        'status',
        'vendor',
        'product_type',
        'crm_product_kind',
        'crm_locked_at',
        'shopify_updated_at',
        'raw_json',
    ];

    public const KIND_STANDARD = 'standard';

    public const KIND_BUNDLE = 'bundle';

    /** @var list<string> */
    public const CRM_PRODUCT_KINDS = [
        self::KIND_STANDARD,
        self::KIND_BUNDLE,
    ];

    protected $casts = [
        'connection_id' => 'integer',
        'crm_locked_at' => 'datetime',
        'shopify_updated_at' => 'datetime',
        'raw_json' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ClientAccountShopifyConnection::class, 'connection_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ShopifyProductVariant::class, 'shopify_product_id');
    }

    public function isCrmLocked(): bool
    {
        return $this->crm_locked_at !== null;
    }

    public function isBundle(): bool
    {
        return strtolower(trim((string) ($this->crm_product_kind ?? self::KIND_STANDARD))) === self::KIND_BUNDLE;
    }

    public static function normalizeCrmProductKind($value): string
    {
        $v = strtolower(trim((string) ($value ?? '')));
        if ($v === self::KIND_BUNDLE || $v === 'bundle') {
            return self::KIND_BUNDLE;
        }

        return self::KIND_STANDARD;
    }

    public static function crmProductKindLabel(string $kind): string
    {
        return self::normalizeCrmProductKind($kind) === self::KIND_BUNDLE
            ? 'Bundle'
            : 'Standard Product';
    }
}
