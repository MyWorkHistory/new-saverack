<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccountShipHeroStoreMeta extends Model
{
    public const TYPE_API = 'api';

    public const TYPE_AMAZON = 'amazon';

    public const TYPE_SHOPIFY = 'shopify';

    public const TYPE_WOOCOMMERCE = 'woocommerce';

    public const TYPE_WALMART = 'walmart';

    public const TYPE_ETSY = 'etsy';

    public const TYPE_TIKTOK = 'tiktok';

    public const TYPE_BIGCOMMERCE = 'bigcommerce';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_API,
        self::TYPE_AMAZON,
        self::TYPE_SHOPIFY,
        self::TYPE_WOOCOMMERCE,
        self::TYPE_WALMART,
        self::TYPE_ETSY,
        self::TYPE_TIKTOK,
        self::TYPE_BIGCOMMERCE,
    ];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_API => 'Public API',
        self::TYPE_AMAZON => 'Amazon',
        self::TYPE_SHOPIFY => 'Shopify',
        self::TYPE_WOOCOMMERCE => 'WooCommerce',
        self::TYPE_WALMART => 'Walmart',
        self::TYPE_ETSY => 'Etsy',
        self::TYPE_TIKTOK => 'TikTok',
        self::TYPE_BIGCOMMERCE => 'BigCommerce',
    ];

    protected $table = 'client_account_shiphero_store_meta';

    protected $fillable = [
        'client_account_id',
        'store_key',
        'shop_id',
        'store_type',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public static function typeLabel(?string $type): ?string
    {
        if ($type === null || trim($type) === '') {
            return null;
        }

        $slug = strtolower(trim($type));

        return self::TYPE_LABELS[$slug] ?? null;
    }

    public static function isValidType(?string $type): bool
    {
        if ($type === null || trim($type) === '') {
            return false;
        }

        return in_array(strtolower(trim($type)), self::TYPES, true);
    }
}
