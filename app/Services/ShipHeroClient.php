<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ShipHeroClient
{
    /** Option key for {@see ShipHeroClient::query()}: tolerate top-level GraphQL `errors` when this mutation field has `request_id`. */
    public const OPTION_GRAPHQL_SUCCESS_FIELD = 'graphql_success_field';

    /** Option key for {@see ShipHeroClient::query()}: use this ShipHero refresh token instead of {@see SHIPHERO_REFRESH_TOKEN}. */
    public const OPTION_REFRESH_TOKEN = 'refresh_token';

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
            return $this->refreshAccessToken($refresh);
        });
    }

    /**
     * Access token for a 3PL child-account developer refresh token (User Hold mutations).
     */
    public function accessTokenForRefreshToken(string $refreshToken): string
    {
        $refresh = trim($refreshToken);
        if ($refresh === '') {
            throw new RuntimeException('ShipHero client refresh token is empty.');
        }

        $cacheKey = 'shiphero.access_token.'.hash('sha256', $refresh);

        return Cache::remember($cacheKey, now()->addDays(20), function () use ($refresh) {
            return $this->refreshAccessToken($refresh);
        });
    }

    private function refreshAccessToken(string $refresh): string
    {
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
    }

    /**
     * Execute a GraphQL operation against the ShipHero public API.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $options  e.g. {@see self::OPTION_GRAPHQL_SUCCESS_FIELD} => 'order_add_attachment'
     * @return array<string, mixed>  Parsed JSON body (includes "data" / "errors")
     */
    public function query(string $graphql, array $variables = [], bool $allowTokenRetry = true, array $options = []): array
    {
        $url = rtrim((string) config('services.shiphero.api_url', 'https://public-api.shiphero.com/graphql'), '/');
        $refreshOverride = isset($options[self::OPTION_REFRESH_TOKEN])
            ? trim((string) $options[self::OPTION_REFRESH_TOKEN])
            : '';
        $token = $refreshOverride !== ''
            ? $this->accessTokenForRefreshToken($refreshOverride)
            : $this->accessToken();
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
                    'connect_timeout' => 8,
                    'timeout' => 25,
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
                $cacheKey = $refreshOverride !== ''
                    ? 'shiphero.access_token.'.hash('sha256', $refreshOverride)
                    : 'shiphero.access_token';
                Cache::forget($cacheKey);

                return $this->query($graphql, $variables, false, $options);
            }

            if (($status < 200 || $status >= 300) && $this->isTransientHttpStatus($status) && $attempt < $maxAttempts) {
                usleep($this->retrySleepMicros($attempt));
                continue;
            }

            if ($status < 200 || $status >= 300) {
                $preview = mb_substr($bodyRaw, 0, 500);
                $msg = 'ShipHero GraphQL request failed (HTTP '.$status.'). Body preview: '.$preview;
                if ($status === 403 && stripos($operation, 'Attachment') !== false) {
                    $msg .= ' For order_add_attachment, ShipHero must accept the request and later fetch your file URL over the public internet: use HTTPS and a non-localhost host (set APP_URL or SHIPHERO_ATTACHMENT_PUBLIC_BASE_URL).';
                }
                throw new RuntimeException($msg);
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

                $successField = isset($options[self::OPTION_GRAPHQL_SUCCESS_FIELD])
                    ? trim((string) $options[self::OPTION_GRAPHQL_SUCCESS_FIELD])
                    : '';
                if ($successField !== '' && $this->graphqlMutationHasRequestId($json, $successField)) {
                    Log::warning('shiphero.graphql.errors_ignored_mutation_succeeded', [
                        'operation' => $operation,
                        'success_field' => $successField,
                        'error_preview' => mb_substr($message, 0, 300),
                    ]);

                    return $json;
                }

                if ($successField !== '') {
                    Log::warning('shiphero.graphql.errors_no_success_data', [
                        'operation' => $operation,
                        'success_field' => $successField,
                        'error_preview' => mb_substr($message, 0, 400),
                        'body_preview' => mb_substr((string) json_encode($json), 0, 600),
                    ]);
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
                'connect_timeout' => 8,
                'timeout' => 25,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('ShipHero diagnostic request failed before response: '.$e->getMessage(), 0, $e);
        }

        return [
            'status' => (int) $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * ShipHero sometimes returns top-level `errors` alongside a successful mutation payload
     * (e.g. order_add_attachment). Treat as success only when `data.$field.request_id` is present.
     *
     * @param  array<string, mixed>  $json
     */
    private function graphqlMutationHasRequestId(array $json, string $field): bool
    {
        $data = $json['data'] ?? null;
        if (! is_array($data)) {
            return false;
        }
        $node = $data[$field] ?? null;
        if (! is_array($node)) {
            return false;
        }
        $rid = $node['request_id'] ?? null;
        if (is_int($rid) || is_float($rid)) {
            return true;
        }

        return is_string($rid) && trim($rid) !== '';
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
