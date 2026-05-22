<?php

namespace App\Services;

use App\Models\ClientStore;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifyOrderAdminLinkService
{
    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public function enrichOrder(int $clientAccountId, array $order): array
    {
        try {
            $url = $this->buildAdminUrl($clientAccountId, $order);
            if ($url !== null) {
                $order['shopify_admin_url'] = $url;
            }
        } catch (Throwable $e) {
            Log::warning('shopify.order_admin_link.enrich_failed', [
                'client_account_id' => $clientAccountId,
                'message' => $e->getMessage(),
            ]);
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public function buildAdminUrl(int $clientAccountId, array $order): ?string
    {
        $partnerId = trim((string) ($order['partner_order_id'] ?? ''));
        if ($partnerId === '' || ! ctype_digit($partnerId)) {
            return null;
        }

        $host = $this->resolveShopifyHost($clientAccountId, trim((string) ($order['account'] ?? '')));
        if ($host === null) {
            return null;
        }

        return 'https://'.$host.'/admin/orders/'.$partnerId;
    }

    private function resolveShopifyHost(int $clientAccountId, string $shopName): ?string
    {
        $fromShopName = $this->parseMyshopifyHost($shopName);
        if ($fromShopName !== null) {
            return $fromShopName;
        }

        $stores = ClientStore::query()
            ->where('client_account_id', $clientAccountId)
            ->whereRaw('LOWER(TRIM(marketplace)) = ?', ['shopify'])
            ->get(['name', 'website']);

        if ($stores->isEmpty()) {
            return null;
        }

        if ($stores->count() === 1) {
            return $this->hostFromWebsite((string) $stores->first()->website);
        }

        $slugShop = $this->slugifyStoreKey($shopName);
        foreach ($stores as $store) {
            if ($this->storeMatchesShopName($store, $shopName, $slugShop)) {
                return $this->hostFromWebsite((string) $store->website);
            }
        }

        return null;
    }

    private function storeMatchesShopName(ClientStore $store, string $shopName, string $slugShop): bool
    {
        if ($shopName === '') {
            return false;
        }
        $name = trim((string) $store->name);
        if ($name !== '' && strcasecmp($name, $shopName) === 0) {
            return true;
        }
        if ($slugShop !== '' && $this->slugifyStoreKey($name) === $slugShop) {
            return true;
        }
        $websiteHost = $this->hostFromWebsite((string) $store->website);
        if ($websiteHost !== null && strcasecmp($websiteHost, $shopName) === 0) {
            return true;
        }
        $slugWebsite = $this->slugifyStoreKey($this->stripMyshopifySuffix($websiteHost ?? ''));

        return $slugShop !== '' && $slugWebsite !== '' && $slugShop === $slugWebsite;
    }

    private function parseMyshopifyHost(string $value): ?string
    {
        $t = trim($value);
        if ($t === '') {
            return null;
        }
        $t = preg_replace('#^https?://#i', '', $t) ?? $t;
        $t = preg_replace('#^www\.#i', '', $t) ?? $t;
        $slash = strpos($t, '/');
        if ($slash !== false) {
            $t = substr($t, 0, $slash);
        }
        $lower = strtolower($t);
        if (strpos($lower, '.myshopify.com') !== false) {
            return $lower;
        }

        return null;
    }

    private function hostFromWebsite(string $website): ?string
    {
        $t = trim($website);
        if ($t === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $t)) {
            $t = 'https://'.$t;
        }
        $host = parse_url($t, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return strtolower(trim($host));
    }

    private function stripMyshopifySuffix(string $host): string
    {
        return preg_replace('/\.myshopify\.com$/i', '', trim($host)) ?? trim($host);
    }

    private function slugifyStoreKey(string $value): string
    {
        $s = strtolower(trim($value));
        if ($s === '') {
            return '';
        }
        $s = preg_replace('#^https?://#', '', $s) ?? $s;
        $s = preg_replace('#^www\.#', '', $s) ?? $s;
        $s = $this->stripMyshopifySuffix($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        $s = trim($s, '-');

        return $s;
    }
}
