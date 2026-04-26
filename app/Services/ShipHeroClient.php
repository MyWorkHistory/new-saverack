<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
                $response = Http::withOptions(['connect_timeout' => 5])
                    ->timeout(8)
                    ->asJson()
                    ->post($authBase.'/refresh', [
                        'refresh_token' => $refresh,
                    ]);
            } catch (Throwable $e) {
                throw new RuntimeException('ShipHero token refresh request failed: '.$e->getMessage(), 0, $e);
            }

            if (! $response->successful()) {
                throw new RuntimeException('ShipHero token refresh failed (HTTP '.$response->status().').');
            }

            $body = $response->json();
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
            $response = Http::withOptions(['connect_timeout' => 5])
                ->timeout(10)
                ->withToken($token)
                ->asJson()
                ->post($url, $payload);
        } catch (Throwable $e) {
            throw new RuntimeException('ShipHero GraphQL request failed before response: '.$e->getMessage(), 0, $e);
        }

        if ($response->status() === 401 && $allowTokenRetry) {
            Cache::forget('shiphero.access_token');

            return $this->query($graphql, $variables, false);
        }

        if (! $response->successful()) {
            throw new RuntimeException('ShipHero GraphQL request failed (HTTP '.$response->status().').');
        }

        $json = $response->json();
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
}
