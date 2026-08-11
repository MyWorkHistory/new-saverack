<?php

namespace App\Services;

use App\Jobs\RunShopifyBootstrapImportJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ShopifyConnectionService
{
    /** @var list<string> */
    public const WEBHOOK_TOPICS = [
        'ORDERS_CREATE',
        'ORDERS_UPDATED',
        'ORDERS_CANCELLED',
        'FULFILLMENTS_CREATE',
        'FULFILLMENTS_UPDATE',
        'PRODUCTS_CREATE',
        'PRODUCTS_UPDATE',
        'INVENTORY_LEVELS_UPDATE',
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
     * @param  array{shop_domain:string, admin_api_access_token?:string|null, webhook_secret?:string|null, api_version?:string|null, import?:bool}  $input
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
        } elseif (! $connection->exists || ! $connection->hasCredentials()) {
            throw new RuntimeException('Admin API access token is required.');
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

    public function queueBootstrapImport(
        ClientAccountShopifyConnection $connection,
        bool $registerWebhooks = true
    ): void {
        $connection->status = ClientAccountShopifyConnection::STATUS_IMPORTING;
        $connection->last_error = null;
        $connection->save();

        RunShopifyBootstrapImportJob::dispatch((int) $connection->id, $registerWebhooks);
    }

    public function registerWebhooks(ClientAccountShopifyConnection $connection): int
    {
        $callback = trim((string) config('services.shopify.webhook_url', ''));
        if ($callback === '') {
            throw new RuntimeException('SHOPIFY_WEBHOOK_URL is not configured.');
        }

        $client = $this->client->forConnection($connection);
        $created = 0;

        foreach (self::WEBHOOK_TOPICS as $topic) {
            $data = $client->graphql(
                <<<'GQL'
mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $callbackUrl: URL!) {
  webhookSubscriptionCreate(
    topic: $topic
    webhookSubscription: { format: JSON, callbackUrl: $callbackUrl }
  ) {
    userErrors { field message }
    webhookSubscription { id topic }
  }
}
GQL
                ,
                [
                    'topic' => $topic,
                    'callbackUrl' => $callback,
                ]
            );

            $payload = is_array($data['webhookSubscriptionCreate'] ?? null)
                ? $data['webhookSubscriptionCreate']
                : [];
            $errors = is_array($payload['userErrors'] ?? null) ? $payload['userErrors'] : [];
            if ($errors !== []) {
                $msg = (string) ($errors[0]['message'] ?? 'Webhook create failed');
                // Already exists is fine for re-connect.
                if (! str_contains(strtolower($msg), 'already') && ! str_contains(strtolower($msg), 'taken')) {
                    throw new RuntimeException($topic.': '.$msg);
                }
            } else {
                $created++;
            }
        }

        return $created;
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
