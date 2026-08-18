<?php

namespace App\Services;

use App\Models\ClientAccountShopifyConnection;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ShopifyClient
{
    /** @var Client|null */
    private $http;

    /** @var ShopifyOAuthService */
    private $oauth;

    /** @var bool */
    private $triedExpiringMigration = false;

    public function __construct(ShopifyOAuthService $oauth)
    {
        $this->oauth = $oauth;
    }

    public function forConnection(ClientAccountShopifyConnection $connection): self
    {
        $clone = clone $this;
        $clone->connection = $connection;
        $clone->triedExpiringMigration = false;

        return $clone;
    }

    /** @var ClientAccountShopifyConnection|null */
    private $connection;

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array
    {
        $connection = $this->requireConnection();
        $this->oauth->ensureFreshAccessToken($connection);

        try {
            return $this->postGraphql($query, $variables);
        } catch (RuntimeException $e) {
            if (! $this->triedExpiringMigration && $this->oauth->tryMigrateNonExpiringToken($connection, $e)) {
                $this->triedExpiringMigration = true;

                return $this->postGraphql($query, $variables);
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function postGraphql(string $query, array $variables = []): array
    {
        $connection = $this->requireConnection();
        $domain = $connection->normalizedShopDomain();
        $token = trim((string) $connection->admin_api_access_token);
        if ($domain === '' || $token === '') {
            throw new RuntimeException('Shopify connection credentials are incomplete.');
        }

        $version = trim((string) ($connection->api_version ?: config('services.shopify.api_version', '2025-01')));
        $url = 'https://'.$domain.'/admin/api/'.$version.'/graphql.json';

        try {
            $response = $this->http()->post($url, [
                'headers' => [
                    'X-Shopify-Access-Token' => $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'query' => $query,
                    'variables' => (object) $variables,
                ],
                'connect_timeout' => 5,
                'timeout' => 25,
            ]);
        } catch (Throwable $e) {
            Log::warning('shopify.graphql.request_failed', [
                'shop' => $domain,
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Shopify GraphQL request failed: '.$e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $body = json_decode($raw, true);
        if (! is_array($body)) {
            $snippet = mb_substr(trim($raw), 0, 240);
            throw new RuntimeException(
                'Shopify GraphQL returned invalid JSON (HTTP '.$status.($snippet !== '' ? ': '.$snippet : '').').'
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Shopify GraphQL HTTP error: '.$this->graphqlHttpErrorMessage($status, $body));
        }

        return $this->interpretGraphqlBody($body, $domain);
    }

    /**
     * Shopify often returns HTTP 200 with both data and field-level ACCESS_DENIED errors
     * (email / shippingAddress). Throwing here aborted order import while products still worked.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function interpretGraphqlBody(array $body, string $shop = ''): array
    {
        $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];
        $data = $body['data'] ?? null;
        $hasUsableData = is_array($data) && $this->graphqlDataHasValue($data);

        if ($errors !== [] && $hasUsableData) {
            Log::warning('shopify.graphql.partial_errors', [
                'shop' => $shop,
                'message' => $this->firstGraphqlErrorMessage($errors),
            ]);
        }

        if ($hasUsableData) {
            return $data;
        }

        if ($errors !== []) {
            throw new RuntimeException('Shopify GraphQL error: '.$this->firstGraphqlErrorMessage($errors));
        }

        throw new RuntimeException('Shopify GraphQL response missing data.');
    }

    /**
     * @param  list<mixed>  $errors
     */
    private function firstGraphqlErrorMessage(array $errors): string
    {
        $first = $errors[0] ?? null;
        if (is_string($first) && trim($first) !== '') {
            return trim($first);
        }
        if (is_array($first)) {
            return (string) ($first['message'] ?? 'GraphQL error');
        }

        return 'GraphQL error';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function graphqlDataHasValue(array $data): bool
    {
        foreach ($data as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function graphqlHttpErrorMessage(int $status, array $body): string
    {
        $errors = $body['errors'] ?? null;
        if (is_string($errors) && trim($errors) !== '') {
            return trim($errors);
        }
        if (is_array($errors) && $errors !== []) {
            $first = $errors[0] ?? null;
            if (is_string($first) && trim($first) !== '') {
                return trim($first);
            }
            if (is_array($first) && isset($first['message']) && is_string($first['message'])) {
                return $first['message'];
            }
            $encoded = json_encode($errors);
            if (is_string($encoded) && $encoded !== '' && $encoded !== '[]') {
                return $encoded;
            }
        }

        return 'HTTP '.$status;
    }

    /**
     * @return array{name:?string, myshopifyDomain:?string, email:?string, domains:list<string>}
     */
    public function shopInfo(): array
    {
        try {
            $data = $this->graphql(<<<'GQL'
query ShopInfo {
  shop {
    name
    myshopifyDomain
    email
    primaryDomain { host }
    domains(first: 25) {
      edges { node { host } }
    }
  }
}
GQL);
        } catch (RuntimeException $e) {
            // Older API versions / restricted shops may lack domains; fall back.
            $data = $this->graphql(<<<'GQL'
query ShopInfoBasic {
  shop {
    name
    myshopifyDomain
    email
  }
}
GQL);
        }

        $shop = is_array($data['shop'] ?? null) ? $data['shop'] : [];
        $domains = [];
        if (! empty($shop['myshopifyDomain'])) {
            $domains[] = (string) $shop['myshopifyDomain'];
        }
        if (! empty($shop['primaryDomain']['host'])) {
            $domains[] = (string) $shop['primaryDomain']['host'];
        }
        foreach (($shop['domains']['edges'] ?? []) as $edge) {
            $host = $edge['node']['host'] ?? null;
            if (is_string($host) && trim($host) !== '') {
                $domains[] = trim($host);
            }
        }

        $normalized = [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
            $domain = rtrim($domain, '/');
            if ($domain !== '' && ! in_array($domain, $normalized, true)) {
                $normalized[] = $domain;
            }
        }

        return [
            'name' => isset($shop['name']) ? (string) $shop['name'] : null,
            'myshopifyDomain' => isset($shop['myshopifyDomain']) ? (string) $shop['myshopifyDomain'] : null,
            'email' => isset($shop['email']) ? (string) $shop['email'] : null,
            'domains' => $normalized,
        ];
    }

    private function requireConnection(): ClientAccountShopifyConnection
    {
        if ($this->connection === null) {
            throw new RuntimeException('ShopifyClient has no connection bound. Call forConnection() first.');
        }

        return $this->connection;
    }

    private function http(): Client
    {
        if ($this->http === null) {
            $this->http = new Client([
                'http_errors' => false,
            ]);
        }

        return $this->http;
    }
}
