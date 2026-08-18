<?php

namespace App\Services;

use App\Jobs\RunShopifyBootstrapImportJob;
use App\Jobs\RunShopifyOrderResyncJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ShopifyConnectionService
{
    /** @var list<string> */
    public const WEBHOOK_TOPICS = [
        'ORDERS_CREATE',
        'ORDERS_UPDATED',
        'ORDERS_EDITED',
        'ORDERS_CANCELLED',
        'ORDERS_DELETE',
        'PRODUCTS_CREATE',
        'PRODUCTS_UPDATE',
        'PRODUCTS_DELETE',
        'INVENTORY_LEVELS_UPDATE',
        // Optional: requires read_fulfillments (orders/updated + orders/edited cover Phase 1)
        'FULFILLMENTS_CREATE',
        'FULFILLMENTS_UPDATE',
    ];

    /** @var list<string> */
    public const OPTIONAL_WEBHOOK_TOPICS = [
        'FULFILLMENTS_CREATE',
        'FULFILLMENTS_UPDATE',
    ];

    /** @var ShopifyClient */
    private $client;

    /** @var ShopifyBootstrapImportService */
    private $bootstrap;

    public function __construct(ShopifyClient $client, ShopifyBootstrapImportService $bootstrap)
    {
        $this->client = $client;
        $this->bootstrap = $bootstrap;
    }

    public function getForAccount(int $clientAccountId): ?ClientAccountShopifyConnection
    {
        return ClientAccountShopifyConnection::query()
            ->where('client_account_id', $clientAccountId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(?ClientAccountShopifyConnection $connection): array
    {
        if ($connection === null) {
            return [
                'connected' => false,
                'status' => ClientAccountShopifyConnection::STATUS_DISCONNECTED,
                'shop_domain' => null,
                'shop_name' => null,
                'api_version' => (string) config('services.shopify.api_version', '2025-01'),
                'has_token' => false,
                'connected_at' => null,
                'last_sync_at' => null,
                'last_product_sync_at' => null,
                'last_order_sync_at' => null,
                'last_error' => null,
            ];
        }

        $status = (string) $connection->status;

        return [
            'connected' => in_array($status, [
                ClientAccountShopifyConnection::STATUS_CONNECTED,
                ClientAccountShopifyConnection::STATUS_IMPORTING,
            ], true),
            'status' => $status,
            'shop_domain' => $connection->normalizedShopDomain(),
            'shop_name' => $connection->shop_name,
            'api_version' => $connection->api_version,
            'has_token' => $connection->hasCredentials(),
            'connected_at' => optional($connection->connected_at)->toIso8601String(),
            'last_sync_at' => optional($connection->last_sync_at)->toIso8601String(),
            'last_product_sync_at' => optional($connection->last_product_sync_at)->toIso8601String(),
            'last_order_sync_at' => optional($connection->last_order_sync_at)->toIso8601String(),
            'last_error' => $connection->last_error,
        ];
    }

    /**
     * Verify credentials quickly, then queue bootstrap import (avoids Cloudflare/PHP request timeouts).
     *
     * @param  array{shop_domain:string, admin_api_access_token?:string|null, refresh_token?:string|null, expires_in?:int|null, refresh_token_expires_in?:int|null, webhook_secret?:string|null, api_version?:string|null, import?:bool}  $input
     */
    public function connectAndImport(ClientAccount $account, array $input): ClientAccountShopifyConnection
    {
        $domain = $this->normalizeDomain((string) ($input['shop_domain'] ?? ''));
        if ($domain === '') {
            throw new RuntimeException('Shop domain is required.');
        }

        $connection = ClientAccountShopifyConnection::query()->firstOrNew([
            'client_account_id' => (int) $account->id,
        ]);
        $connection->shop_domain = $domain;
        $connection->api_version = trim((string) ($input['api_version'] ?? config('services.shopify.api_version', '2025-01')))
            ?: '2025-01';

        $token = isset($input['admin_api_access_token']) ? trim((string) $input['admin_api_access_token']) : '';
        if ($token !== '') {
            $connection->admin_api_access_token = $token;
            if (! array_key_exists('refresh_token', $input)) {
                $connection->refresh_token = null;
                $connection->access_token_expires_at = null;
                $connection->refresh_token_expires_at = null;
            }
        } elseif (! $connection->exists || ! $connection->hasCredentials()) {
            throw new RuntimeException('Admin API access token is required.');
        }

        if (array_key_exists('refresh_token', $input) || array_key_exists('expires_in', $input)) {
            app(ShopifyOAuthService::class)->persistTokenPayload($connection, [
                'access_token' => $token !== '' ? $token : (string) $connection->admin_api_access_token,
                'refresh_token' => $input['refresh_token'] ?? null,
                'expires_in' => $input['expires_in'] ?? null,
                'refresh_token_expires_in' => $input['refresh_token_expires_in'] ?? null,
            ]);
        }

        if (array_key_exists('webhook_secret', $input)) {
            $secret = trim((string) ($input['webhook_secret'] ?? ''));
            $connection->webhook_secret = $secret !== '' ? $secret : null;
        }

        $connection->last_error = null;
        $connection->save();

        try {
            $shop = $this->client->forConnection($connection)->shopInfo();
            $connection->shop_name = $shop['name'] ?? $connection->shop_name;
            $canonical = $this->normalizeDomain((string) ($shop['myshopifyDomain'] ?? ''));
            if ($canonical !== '') {
                $connection->shop_domain = $canonical;
            }
            $aliasSeeds = array_merge(
                [$domain],
                is_array($shop['domains'] ?? null) ? $shop['domains'] : []
            );
            $connection->mergeShopDomainAliases($aliasSeeds);
            $connection->connected_at = $connection->connected_at ?? now();
            $connection->save();
        } catch (Throwable $e) {
            $connection->status = ClientAccountShopifyConnection::STATUS_ERROR;
            $connection->last_error = mb_substr($e->getMessage(), 0, 1000);
            $connection->save();
            throw $e;
        }

        $shouldImport = ! array_key_exists('import', $input) || (bool) $input['import'];
        if ($shouldImport) {
            $this->queueBootstrapImport($connection, true);

            return $connection->fresh();
        }

        $connection->status = ClientAccountShopifyConnection::STATUS_CONNECTED;
        $connection->save();

        try {
            $this->registerWebhooks($connection);
        } catch (Throwable $e) {
            Log::warning('shopify.webhooks.register_failed', [
                'connection_id' => $connection->id,
                'message' => $e->getMessage(),
            ]);
            $connection->last_error = mb_substr('Connected but webhook registration failed: '.$e->getMessage(), 0, 1000);
            $connection->save();
        }

        return $connection->fresh();
    }

    public function disconnect(ClientAccountShopifyConnection $connection): void
    {
        $connection->admin_api_access_token = null;
        $connection->refresh_token = null;
        $connection->access_token_expires_at = null;
        $connection->refresh_token_expires_at = null;
        $connection->status = ClientAccountShopifyConnection::STATUS_DISCONNECTED;
        $connection->last_error = null;
        $connection->save();
    }

    /**
     * Queue a full re-import (HTTP-safe). Prefer this over running importAll in a web request.
     */
    public function syncNow(ClientAccountShopifyConnection $connection): ClientAccountShopifyConnection
    {
        if (! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection has no credentials.');
        }

        $this->queueBootstrapImport($connection, false);

        return $connection->fresh();
    }

    /**
     * Synchronous import for artisan / tests.
     */
    public function syncNowInline(ClientAccountShopifyConnection $connection): ClientAccountShopifyConnection
    {
        if (! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection has no credentials.');
        }

        $this->bootstrap->importAll($connection);

        return $connection->fresh();
    }

    /**
     * Queue an orders-only re-sync (unfulfilled or after date). Does not flip full-import "importing" status.
     *
     * @param  array{mode:string, after_date?:string|null}  $input
     * @return array{queued:true, mode:string, connection:ClientAccountShopifyConnection}
     */
    public function queueOrderResync(ClientAccountShopifyConnection $connection, array $input): array
    {
        if (! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection has no credentials.');
        }

        $mode = (string) ($input['mode'] ?? '');
        if (! in_array($mode, [
            RunShopifyOrderResyncJob::MODE_UNFULFILLED,
            RunShopifyOrderResyncJob::MODE_AFTER_DATE,
        ], true)) {
            throw new RuntimeException('Invalid order re-sync mode.');
        }

        $afterDate = null;
        if ($mode === RunShopifyOrderResyncJob::MODE_AFTER_DATE) {
            $raw = trim((string) ($input['after_date'] ?? ''));
            if ($raw === '') {
                throw new RuntimeException('After date is required.');
            }
            try {
                $afterDate = Carbon::parse($raw)->utc()->toDateString();
            } catch (Throwable $e) {
                throw new RuntimeException('Invalid after date.');
            }
        }

        $connection->last_error = null;
        $connection->save();

        RunShopifyOrderResyncJob::dispatch((int) $connection->id, $mode, $afterDate);

        return [
            'queued' => true,
            'mode' => $mode,
            'connection' => $connection->fresh() ?? $connection,
        ];
    }

    /**
     * Inline sync of one Shopify order by name (#1234).
     *
     * @return array{queued:false, synced:1, order:ShopifyOrder, connection:ClientAccountShopifyConnection}
     */
    public function syncOrderByNumber(
        ClientAccountShopifyConnection $connection,
        string $orderNumber,
        ShopifyOrderSyncService $orders
    ): array {
        if (! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection has no credentials.');
        }

        $order = $orders->syncOrderByName($connection, $orderNumber);

        return [
            'queued' => false,
            'synced' => 1,
            'order' => $order,
            'connection' => $connection->fresh() ?? $connection,
        ];
    }

    public function queueBootstrapImport(
        ClientAccountShopifyConnection $connection,
        bool $registerWebhooks = true
    ): void {
        $connection->status = ClientAccountShopifyConnection::STATUS_IMPORTING;
        $connection->last_error = null;
        $connection->save();

        RunShopifyBootstrapImportJob::dispatch((int) $connection->id, $registerWebhooks);
    }

    /**
     * @return array{created:int, skipped:list<string>}
     */
    public function registerWebhooks(ClientAccountShopifyConnection $connection): array
    {
        $callback = $this->resolvedWebhookCallbackUrl();

        $client = $this->client->forConnection($connection);
        $created = 0;
        $skipped = [];
        $hardFailures = [];

        foreach (self::WEBHOOK_TOPICS as $topic) {
            try {
                $data = $client->graphql(
                    <<<'GQL'
mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $uri: String!) {
  webhookSubscriptionCreate(
    topic: $topic
    webhookSubscription: { format: JSON, uri: $uri }
  ) {
    userErrors { field message }
    webhookSubscription { id topic }
  }
}
GQL
                    ,
                    [
                        'topic' => $topic,
                        'uri' => $callback,
                    ]
                );
            } catch (Throwable $e) {
                $hardFailures[] = $topic.': '.$e->getMessage();
                Log::warning('shopify.webhooks.topic_failed', [
                    'connection_id' => $connection->id,
                    'topic' => $topic,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }

            $payload = is_array($data['webhookSubscriptionCreate'] ?? null)
                ? $data['webhookSubscriptionCreate']
                : [];
            $errors = is_array($payload['userErrors'] ?? null) ? $payload['userErrors'] : [];
            if ($errors !== []) {
                $msg = (string) ($errors[0]['message'] ?? 'Webhook create failed');
                $lower = strtolower($msg);
                // Already exists is fine for re-connect.
                if (str_contains($lower, 'already') || str_contains($lower, 'taken')) {
                    $created++;
                    continue;
                }
                // Missing scope / topic not allowed for this app — skip optional topics.
                $denied = str_contains($lower, 'cannot create')
                    || str_contains($lower, 'access')
                    || str_contains($lower, 'scope')
                    || str_contains($lower, 'protected');
                if ($denied && in_array($topic, self::OPTIONAL_WEBHOOK_TOPICS, true)) {
                    $skipped[] = $topic.': '.$msg;
                    Log::warning('shopify.webhooks.topic_skipped', [
                        'connection_id' => $connection->id,
                        'topic' => $topic,
                        'message' => $msg,
                    ]);
                    continue;
                }
                $hardFailures[] = $topic.': '.$msg;
                continue;
            }

            $created++;
        }

        if ($created === 0) {
            throw new RuntimeException(
                'No Shopify webhook topics could be registered. '.implode(' ', $hardFailures)
            );
        }

        if ($hardFailures !== []) {
            Log::warning('shopify.webhooks.partial_register', [
                'connection_id' => $connection->id,
                'created' => $created,
                'failures' => $hardFailures,
            ]);
        }

        return ['created' => $created, 'skipped' => array_merge($skipped, $hardFailures)];
    }

    /**
     * Absolute HTTPS URL Shopify can POST to. Prefer SHOPIFY_WEBHOOK_URL; else APP_URL + /api/shopify/webhook.
     */
    public function resolvedWebhookCallbackUrl(): string
    {
        $callback = trim((string) config('services.shopify.webhook_url', ''), " \t\n\r\0\x0B\"'");
        if ($callback === '') {
            $appUrl = rtrim(trim((string) config('app.url', ''), " \t\n\r\0\x0B\"'"), '/');
            if ($appUrl !== '') {
                $callback = $appUrl.'/api/shopify/webhook';
            }
        }

        if ($callback === '') {
            throw new RuntimeException(
                'SHOPIFY_WEBHOOK_URL is not configured. Set e.g. SHOPIFY_WEBHOOK_URL=https://app.saverack.com/api/shopify/webhook'
            );
        }

        // Relative path → prefix APP_URL
        if (str_starts_with($callback, '/')) {
            $appUrl = rtrim(trim((string) config('app.url', ''), " \t\n\r\0\x0B\"'"), '/');
            if ($appUrl === '') {
                throw new RuntimeException(
                    'SHOPIFY_WEBHOOK_URL is a path ('.$callback.') but APP_URL is empty. Use a full https URL.'
                );
            }
            $callback = $appUrl.$callback;
        }

        if (! preg_match('#^https://#i', $callback)) {
            throw new RuntimeException(
                'Shopify webhook callback must be an https URL. Got: '.$callback
            );
        }

        if (filter_var($callback, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException(
                'SHOPIFY_WEBHOOK_URL is not a valid URL: '.$callback
            );
        }

        return $callback;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');
        if ($domain !== '' && ! str_contains($domain, '.')) {
            $domain .= '.myshopify.com';
        }

        return $domain;
    }
}
