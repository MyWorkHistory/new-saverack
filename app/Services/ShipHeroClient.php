<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ShipHeroClient
{
    /**
     * Exchange refresh token for a bearer access token (cached ~20 days).
     */
    public function accessToken(): string
    {
        $refresh = config('services.shiphero.refresh_token');
        if (! is_string($refresh) || $refresh === '') {
            throw new RuntimeException('ShipHero is not configured: set SHIPHERO_REFRESH_TOKEN in .env.');
        }

        return Cache::remember('shiphero.access_token', now()->addDays(20), function () use ($refresh) {
            $authBase = rtrim((string) config('services.shiphero.auth_url', 'https://public-api.shiphero.com/auth'), '/');
            try {
                $response = $this->http()->post($authBase.'/refresh', [
                    'json' => [
                        'refresh_token' => $refresh,
                    ],
                    'connect_timeout' => 3,
                    'timeout' => 6,
                ]);
            } catch (Throwable $e) {
                throw new RuntimeException('ShipHero token refresh request failed: '.$e->getMessage(), 0, $e);
            }

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new RuntimeException('ShipHero token refresh failed (HTTP '.$response->getStatusCode().').');
            }

            $body = json_decode((string) $response->getBody(), true);
            $token = is_array($body) ? ($body['access_token'] ?? null) : null;
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('ShipHero token refresh returned no access_token.');
            }

            return $token;
        });
    }

    /**
     * Execute a GraphQL operation against the ShipHero public API.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>  Parsed JSON body (includes "data" / "errors")
     */
    public function query(string $graphql, array $variables = [], bool $allowTokenRetry = true): array
    {
        $url = rtrim((string) config('services.shiphero.api_url', 'https://public-api.shiphero.com/graphql'), '/');
        $token = $this->accessToken();

        $payload = ['query' => $graphql];
        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        try {
            $response = $this->http()->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
                'connect_timeout' => 3,
                'timeout' => 6,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('ShipHero GraphQL request failed before response: '.$e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() === 401 && $allowTokenRetry) {
            Cache::forget('shiphero.access_token');

            return $this->query($graphql, $variables, false);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('ShipHero GraphQL request failed (HTTP '.$response->getStatusCode().').');
        }

        $json = json_decode((string) $response->getBody(), true);
        if (! is_array($json)) {
            throw new RuntimeException('ShipHero returned invalid JSON.');
        }

        if (! empty($json['errors']) && is_array($json['errors'])) {
            $first = $json['errors'][0];
            $message = is_array($first)
                ? (string) ($first['message'] ?? json_encode($first))
                : (string) $first;

            throw new RuntimeException('ShipHero: '.$message);
        }

        return $json;
    }

    private function http(): Client
    {
        return new Client([
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }
}
