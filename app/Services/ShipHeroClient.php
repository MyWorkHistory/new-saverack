<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
        $operation = $this->extractOperationName($graphql);

        $payload = ['query' => $graphql];
        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        $maxAttempts = 3;
        $lastTransportError = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            Log::info('shiphero.graphql.request.start', [
                'operation' => $operation,
                'attempt' => $attempt,
                'allow_token_retry' => $allowTokenRetry,
            ]);
            try {
                $response = $this->http()->post($url, [
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                    ],
                    'json' => $payload,
                    'connect_timeout' => 4,
                    'timeout' => 10,
                ]);
            } catch (Throwable $e) {
                $lastTransportError = $e;
                Log::warning('shiphero.graphql.request.transport_exception', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);
                if ($attempt < $maxAttempts) {
                    usleep($this->retrySleepMicros($attempt));
                    continue;
                }
                throw new RuntimeException('ShipHero GraphQL request failed before response: '.$e->getMessage(), 0, $e);
            }

            $status = $response->getStatusCode();
            $bodyRaw = (string) $response->getBody();
            Log::info('shiphero.graphql.response.received', [
                'operation' => $operation,
                'attempt' => $attempt,
                'status' => $status,
                'body_bytes' => strlen($bodyRaw),
            ]);
            if ($status === 401 && $allowTokenRetry) {
                Cache::forget('shiphero.access_token');

                return $this->query($graphql, $variables, false);
            }

            if (($status < 200 || $status >= 300) && $this->isTransientHttpStatus($status) && $attempt < $maxAttempts) {
                usleep($this->retrySleepMicros($attempt));
                continue;
            }

            if ($status < 200 || $status >= 300) {
                $preview = mb_substr($bodyRaw, 0, 500);
                throw new RuntimeException(
                    'ShipHero GraphQL request failed (HTTP '.$status.'). Body preview: '.$preview
                );
            }

            $json = json_decode($bodyRaw, true);
            if (! is_array($json)) {
                if ($attempt < $maxAttempts) {
                    usleep($this->retrySleepMicros($attempt));
                    continue;
                }
                throw new RuntimeException('ShipHero returned invalid JSON.');
            }

            if (! empty($json['errors']) && is_array($json['errors'])) {
                $first = $json['errors'][0];
                $message = is_array($first)
                    ? (string) ($first['message'] ?? json_encode($first))
                    : (string) $first;

                if ($this->isTransientApiErrorMessage($message) && $attempt < $maxAttempts) {
                    usleep($this->retrySleepMicros($attempt));
                    continue;
                }

                throw new RuntimeException('ShipHero: '.$message);
            }

            return $json;
        }

        throw new RuntimeException(
            'ShipHero request failed after retries.'
            .($lastTransportError ? ' Last error: '.$lastTransportError->getMessage() : '')
        );
    }

    /**
     * Execute a single GraphQL request and return raw HTTP diagnostics.
     *
     * @param  array<string, mixed>  $variables
     * @return array{status:int, body:string}
     */
    public function queryRawDiagnostic(string $graphql, array $variables = []): array
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
                'connect_timeout' => 4,
                'timeout' => 10,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('ShipHero diagnostic request failed before response: '.$e->getMessage(), 0, $e);
        }

        return [
            'status' => (int) $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }

    private function extractOperationName(string $graphql): string
    {
        if (preg_match('/\b(query|mutation)\s+([A-Za-z0-9_]+)/', $graphql, $matches) === 1) {
            return (string) $matches[2];
        }

        return 'anonymous_operation';
    }

    private function isTransientHttpStatus(int $status): bool
    {
        return in_array($status, [429, 500, 502, 503, 504, 520, 521, 522, 523, 524], true);
    }

    private function isTransientApiErrorMessage(string $message): bool
    {
        $m = strtolower(trim($message));
        return str_contains($m, '502')
            || str_contains($m, '503')
            || str_contains($m, '504')
            || str_contains($m, 'cloudflare')
            || str_contains($m, 'bad gateway')
            || str_contains($m, 'temporarily unavailable')
            || str_contains($m, 'timeout');
    }

    private function retrySleepMicros(int $attempt): int
    {
        if ($attempt <= 1) {
            return 200000;
        }
        if ($attempt === 2) {
            return 700000;
        }
        return 1200000;
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
