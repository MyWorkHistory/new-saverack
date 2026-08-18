<?php

namespace App\Services;

use App\Models\ClientAccountShopifyConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ShopifyOAuthService
{
    private const STATE_TTL_SECONDS = 900;

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function clientId(): string
    {
        return trim((string) config('services.shopify.client_id', ''));
    }

    public function clientSecret(): string
    {
        return trim((string) config('services.shopify.client_secret', ''));
    }

    public function scopes(): string
    {
        $scopes = trim((string) config('services.shopify.scopes', ''));

        return $scopes !== ''
            ? $scopes
            : 'read_products,write_products,read_inventory,write_inventory,read_orders,write_orders,read_merchant_managed_fulfillment_orders,write_merchant_managed_fulfillment_orders,read_locations';
    }

    public function redirectUri(): string
    {
        $configured = trim((string) config('services.shopify.oauth_redirect_uri', ''));
        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/api/shopify/oauth/callback';
    }

    public function installUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/shopify/oauth/install';
    }

    public function defaultAccountId(): int
    {
        return (int) config('services.shopify.oauth_default_account_id', 0);
    }

    /**
     * Resolve which CRM client account should own this shop install.
     * Priority: explicit account_id → existing connection for shop → SHOPIFY_OAUTH_DEFAULT_ACCOUNT_ID.
     */
    public function resolveAccountIdForShop(string $shopDomain, ?int $explicitAccountId = null): int
    {
        if ($explicitAccountId !== null && $explicitAccountId > 0) {
            return $explicitAccountId;
        }

        $shop = $this->normalizeShopDomain($shopDomain);
        if ($shop !== '') {
            $existing = ClientAccountShopifyConnection::query()
                ->where('shop_domain', $shop)
                ->orderByDesc('id')
                ->first(['client_account_id']);
            if ($existing !== null && (int) $existing->client_account_id > 0) {
                return (int) $existing->client_account_id;
            }
        }

        $pending = $this->peekPendingInstall($shop);
        if ($pending !== null && (int) ($pending['account_id'] ?? 0) > 0) {
            return (int) $pending['account_id'];
        }

        return $this->defaultAccountId();
    }

    public function normalizeShopDomain(string $domain): string
    {
        return ClientAccountShopifyConnection::normalizeShopDomain($domain);
    }

    public function shopHandle(string $shopDomain): string
    {
        $shop = $this->normalizeShopDomain($shopDomain);
        if ($shop === '') {
            return '';
        }
        if (substr($shop, -14) === '.myshopify.com') {
            return substr($shop, 0, -14);
        }

        return $shop;
    }

    /**
     * @param  array{account_id:int, shop:string, user_id?:int|null, import?:bool}  $payload
     */
    public function rememberPendingInstall(array $payload): void
    {
        $shop = $this->normalizeShopDomain((string) ($payload['shop'] ?? ''));
        if ($shop === '') {
            return;
        }
        Cache::put($this->pendingInstallCacheKey($shop), [
            'account_id' => (int) ($payload['account_id'] ?? 0),
            'shop' => $shop,
            'user_id' => isset($payload['user_id']) ? (int) $payload['user_id'] : null,
            'import' => array_key_exists('import', $payload) ? (bool) $payload['import'] : true,
        ], self::STATE_TTL_SECONDS);
    }

    /**
     * @return array{account_id:int, shop:string, user_id:int|null, import:bool}|null
     */
    public function peekPendingInstall(string $shopDomain): ?array
    {
        $shop = $this->normalizeShopDomain($shopDomain);
        if ($shop === '') {
            return null;
        }
        $payload = Cache::get($this->pendingInstallCacheKey($shop));

        return $this->normalizePendingPayload($payload, $shop);
    }

    /**
     * @return array{account_id:int, shop:string, user_id:int|null, import:bool}|null
     */
    public function pullPendingInstall(string $shopDomain): ?array
    {
        $shop = $this->normalizeShopDomain($shopDomain);
        if ($shop === '') {
            return null;
        }
        $payload = Cache::pull($this->pendingInstallCacheKey($shop));

        return $this->normalizePendingPayload($payload, $shop);
    }

    public function usesManagedInstall(): bool
    {
        return (bool) config('services.shopify.oauth_managed_install', true);
    }

    /**
     * First hop from CRM Connect. New Shopify apps block /oauth/authorize until the
     * app is installed via managed install.
     */
    public function connectUrl(string $shopDomain, string $state): string
    {
        if ($this->usesManagedInstall()) {
            return $this->managedInstallUrl($shopDomain);
        }

        return $this->authorizationUrl($shopDomain, $state);
    }

    public function managedInstallUrl(string $shopDomain): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Shopify OAuth is not configured (missing client id/secret).');
        }
        $shop = $this->normalizeShopDomain($shopDomain);
        if ($shop === '') {
            throw new RuntimeException('Shop domain is required.');
        }

        $query = http_build_query([
            'client_id' => $this->clientId(),
        ], '', '&', PHP_QUERY_RFC3986);

        // Do not put /store/{handle}/ in this path. If the current Shopify session
        // cannot access that store, admin.shopify.com shows "Unauthorized Access".
        return 'https://admin.shopify.com/oauth/install?'.$query;
    }

    /**
     * @param  array{account_id:int, shop:string, user_id?:int|null, import?:bool}  $payload
     */
    public function createState(array $payload): string
    {
        $state = Str::random(40);
        Cache::put($this->stateCacheKey($state), [
            'account_id' => (int) ($payload['account_id'] ?? 0),
            'shop' => (string) ($payload['shop'] ?? ''),
            'user_id' => isset($payload['user_id']) ? (int) $payload['user_id'] : null,
            'import' => array_key_exists('import', $payload) ? (bool) $payload['import'] : true,
            'created_at' => now()->toIso8601String(),
        ], self::STATE_TTL_SECONDS);

        return $state;
    }

    /**
     * @return array{account_id:int, shop:string, user_id:int|null, import:bool}|null
     */
    public function pullState(string $state): ?array
    {
        $state = trim($state);
        if ($state === '') {
            return null;
        }

        $key = $this->stateCacheKey($state);
        $payload = Cache::pull($key);
        if (! is_array($payload)) {
            return null;
        }

        $accountId = (int) ($payload['account_id'] ?? 0);
        $shop = $this->normalizeShopDomain((string) ($payload['shop'] ?? ''));
        if ($accountId < 1 || $shop === '') {
            return null;
        }

        return [
            'account_id' => $accountId,
            'shop' => $shop,
            'user_id' => isset($payload['user_id']) ? (int) $payload['user_id'] : null,
            'import' => array_key_exists('import', $payload) ? (bool) $payload['import'] : true,
        ];
    }

    public function authorizationUrl(string $shopDomain, string $state): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Shopify OAuth is not configured (missing client id/secret).');
        }

        $shop = $this->normalizeShopDomain($shopDomain);
        if ($shop === '') {
            throw new RuntimeException('Shop domain is required.');
        }

        $params = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ];
        // Dev Dashboard apps declare scopes on the app. Sending scope= here returns
        // Shopify's "Unauthorized Access" page.
        if ((bool) config('services.shopify.oauth_send_scopes', false)) {
            $params['scope'] = $this->scopes();
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return 'https://'.$shop.'/admin/oauth/authorize?'.$query;
    }

    /**
     * Verify Shopify callback hmac (hex HMAC-SHA256 of sorted query params).
     *
     * @param  array<string, mixed>  $query
     */
    public function verifyCallbackHmac(array $query): bool
    {
        $hmac = (string) ($query['hmac'] ?? '');
        if ($hmac === '' || $this->clientSecret() === '') {
            return false;
        }

        $params = $query;
        unset($params['hmac'], $params['signature']);

        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $parts[] = $key.'='.$value;
        }
        $message = implode('&', $parts);
        $computed = hash_hmac('sha256', $message, $this->clientSecret());

        return hash_equals($computed, $hmac);
    }

    /**
     * Exchange authorization code for an offline Admin API access token.
     *
     * @return array{access_token:string, scope?:string}
     */
    public function exchangeCode(string $shopDomain, string $code): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Shopify OAuth is not configured (missing client id/secret).');
        }

        $shop = $this->normalizeShopDomain($shopDomain);
        $code = trim($code);
        if ($shop === '' || $code === '') {
            throw new RuntimeException('Shop and authorization code are required.');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->timeout(30)
            ->post('https://'.$shop.'/admin/oauth/access_token', [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'code' => $code,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Shopify token exchange failed (HTTP '.$response->status().').'
            );
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Shopify token exchange returned invalid JSON.');
        }

        $token = trim((string) ($data['access_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Shopify token exchange did not return an access token.');
        }

        return [
            'access_token' => $token,
            'scope' => isset($data['scope']) ? (string) $data['scope'] : null,
        ];
    }

    /**
     * Offline token from a session / id_token (managed install + token exchange).
     *
     * @return array{access_token:string, scope?:string}
     */
    public function exchangeSessionToken(string $shopDomain, string $idToken): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Shopify OAuth is not configured (missing client id/secret).');
        }

        $shop = $this->normalizeShopDomain($shopDomain);
        $idToken = trim($idToken);
        if ($shop === '' || $idToken === '') {
            throw new RuntimeException('Shop and session token are required.');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->timeout(30)
            ->post('https://'.$shop.'/admin/oauth/access_token', [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'subject_token' => $idToken,
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Shopify session token exchange failed (HTTP '.$response->status().').'
            );
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Shopify session token exchange returned invalid JSON.');
        }

        $token = trim((string) ($data['access_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Shopify session token exchange did not return an access token.');
        }

        return [
            'access_token' => $token,
            'scope' => isset($data['scope']) ? (string) $data['scope'] : null,
        ];
    }

    private function stateCacheKey(string $state): string
    {
        return 'shopify_oauth_state:'.$state;
    }

    private function pendingInstallCacheKey(string $shop): string
    {
        return 'shopify_oauth_pending_shop:'.$shop;
    }

    /**
     * @param  mixed  $payload
     * @return array{account_id:int, shop:string, user_id:int|null, import:bool}|null
     */
    private function normalizePendingPayload($payload, string $shop): ?array
    {
        if (! is_array($payload)) {
            return null;
        }
        $accountId = (int) ($payload['account_id'] ?? 0);
        if ($accountId < 1) {
            return null;
        }

        return [
            'account_id' => $accountId,
            'shop' => $shop,
            'user_id' => isset($payload['user_id']) ? (int) $payload['user_id'] : null,
            'import' => array_key_exists('import', $payload) ? (bool) $payload['import'] : true,
        ];
    }
}
