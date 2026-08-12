<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientAccountShopifyConnection extends Model
{
    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_ERROR = 'error';

    protected $table = 'client_account_shopify_connections';

    protected $fillable = [
        'client_account_id',
        'shop_domain',
        'admin_api_access_token',
        'api_version',
        'webhook_secret',
        'status',
        'shop_name',
        'connected_at',
        'last_sync_at',
        'last_product_sync_at',
        'last_order_sync_at',
        'last_error',
    ];

    protected $casts = [
        'client_account_id' => 'integer',
        'admin_api_access_token' => 'encrypted',
        'connected_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'last_product_sync_at' => 'datetime',
        'last_order_sync_at' => 'datetime',
    ];

    protected $hidden = [
        'admin_api_access_token',
        'webhook_secret',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(ShopifyLocation::class, 'connection_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(ShopifyProduct::class, 'connection_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ShopifyProductVariant::class, 'connection_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ShopifyOrder::class, 'connection_id');
    }

    public function normalizedShopDomain(): string
    {
        return self::normalizeShopDomain((string) $this->shop_domain);
    }

    public static function normalizeShopDomain(?string $domain): string
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');
        if ($domain !== '' && ! str_contains($domain, '.')) {
            $domain .= '.myshopify.com';
        }

        return $domain;
    }

    public static function findByShopDomain(?string $shopDomain): ?self
    {
        $normalized = self::normalizeShopDomain($shopDomain);
        if ($normalized === '') {
            return null;
        }

        $candidates = self::query()
            ->where('shop_domain', $normalized)
            ->orWhere('shop_domain', 'https://'.$normalized)
            ->orWhere('shop_domain', 'http://'.$normalized)
            ->get();

        foreach ($candidates as $row) {
            if ($row->normalizedShopDomain() === $normalized) {
                return $row;
            }
        }

        foreach (self::query()->cursor() as $row) {
            if ($row->normalizedShopDomain() === $normalized) {
                return $row;
            }
        }

        return null;
    }

    public function hasCredentials(): bool
    {
        return $this->normalizedShopDomain() !== ''
            && is_string($this->admin_api_access_token)
            && trim($this->admin_api_access_token) !== '';
    }
}
