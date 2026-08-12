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

    public function forConnection(ClientAccountShopifyConnection $connection): self
    {
        $clone = clone $this;
        $clone->connection = $connection;

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
        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body)) {
            throw new RuntimeException('Shopify GraphQL returned invalid JSON (HTTP '.$status.').');
        }

        if ($status < 200 || $status >= 300) {
            $message = is_array($body['errors'] ?? null)
                ? json_encode($body['errors'])
                : 'HTTP '.$status;
            throw new RuntimeException('Shopify GraphQL HTTP error: '.$message);
        }

        if (! empty($body['errors']) && is_array($body['errors'])) {
            $first = $body['errors'][0] ?? null;
            $message = is_array($first) ? (string) ($first['message'] ?? 'GraphQL error') : 'GraphQL error';
            throw new RuntimeException('Shopify GraphQL error: '.$message);
        }

        $data = $body['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException('Shopify GraphQL response missing data.');
        }

        return $data;
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
