<?php

namespace App\Services;

use App\Models\ClientAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShipHeroStoreService
{
    private const CACHE_TTL_SECONDS = 86400;

    private const SETTINGS_URL_BASE = 'https://app.shiphero.com/dashboard/stores/settings';

    /** @var ShipHeroClient */
    private $client;

    public function __construct(ShipHeroClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return array{stores: list<array<string, mixed>>, imported_at: string|null}
     */
    public function getCachedForAccount(ClientAccount $account): array
    {
        $cached = Cache::get($this->cacheKey((int) $account->id));
        if (! is_array($cached)) {
            return [
                'stores' => [],
                'imported_at' => null,
            ];
        }

        $stores = $cached['stores'] ?? [];
        if (! is_array($stores)) {
            $stores = [];
        }

        return [
            'stores' => array_values($stores),
            'imported_at' => isset($cached['imported_at']) && is_string($cached['imported_at'])
                ? $cached['imported_at']
                : null,
        ];
    }

    /**
     * @return array{stores: list<array<string, mixed>>, imported_at: string}
     */
    public function importForAccount(ClientAccount $account): array
    {
        $fetched = $this->fetchFromShipHero($account);
        $importedAt = now()->toIso8601String();

        $payload = [
            'stores' => $fetched['stores'],
            'imported_at' => $importedAt,
        ];

        Cache::put($this->cacheKey((int) $account->id), $payload, self::CACHE_TTL_SECONDS);

        return [
            'stores' => $fetched['stores'],
            'imported_at' => $importedAt,
        ];
    }

    /**
     * @return array{stores: list<array<string, mixed>>, request_id: string|null}
     */
    public function fetchFromShipHero(ClientAccount $account): array
    {
        $customerAccountId = $this->resolveCustomerAccountId($account);
        $lastError = null;
        $bestEmpty = null;

        $attempts = [
            function () use ($customerAccountId) {
                return $this->fetchStoresViaUsersQuery($customerAccountId);
            },
            function () use ($account) {
                return $this->fetchStoresViaCustomerToken($account);
            },
        ];

        foreach ($this->userLookupStrategies($account, $customerAccountId) as $variables) {
            $attempts[] = function () use ($variables) {
                return $this->fetchStoresViaUserQuery($variables);
            };
        }

        foreach ($attempts as $attempt) {
            try {
                $result = $attempt();
                if (($result['stores'] ?? []) !== []) {
                    return $result;
                }
                if ($bestEmpty === null) {
                    $bestEmpty = $result;
                }
            } catch (RuntimeException $e) {
                $lastError = $e;
            }
        }

        if ($bestEmpty !== null) {
            Log::info('shiphero.stores.empty', [
                'client_account_id' => $account->id,
                'shiphero_customer_account_id' => $customerAccountId,
                'request_id' => $bestEmpty['request_id'] ?? null,
            ]);

            return $bestEmpty;
        }

        throw $lastError ?? new RuntimeException('Could not load ShipHero stores for this customer account.');
    }

    /**
     * Probe helper when only the ShipHero customer_account_id is known.
     *
     * @return array{stores: list<array<string, mixed>>, request_id: string|null}
     */
    public function fetchFromShipHeroCustomerId(string $customerAccountId): array
    {
        $customerAccountId = trim($customerAccountId);
        if ($customerAccountId === '') {
            throw new RuntimeException('ShipHero customer account ID is required.');
        }

        $account = new ClientAccount([
            'shiphero_customer_account_id' => $customerAccountId,
        ]);

        return $this->fetchFromShipHero($account);
    }

    /**
     * @return array{stores: list<array<string, mixed>>, request_id: string|null}
     */
    private function fetchStoresViaUsersQuery(string $customerAccountId): array
    {
        $graphql = <<<'GQL'
query ShipHeroCustomerStoresUsers($customer_account_id: String!, $first: Int!, $after: String) {
  users(customer_account_id: $customer_account_id) {
    request_id
    complexity
    data(first: $first, after: $after) {
      pageInfo {
        hasNextPage
        endCursor
      }
      edges {
        node {
          id
          email
          is_admin
          stores {
            id
            legacy_id
            shop_name
          }
        }
      }
    }
  }
}
GQL;

        $merged = [];
        $requestId = null;
        $after = null;
        $pages = 0;

        do {
            $pages++;
            if ($pages > 20) {
                break;
            }

            $json = $this->client->query($graphql, [
                'customer_account_id' => $customerAccountId,
                'first' => 50,
                'after' => $after,
            ]);

            if (isset($json['errors']) && is_array($json['errors']) && $json['errors'] !== []) {
                $message = $json['errors'][0]['message'] ?? 'ShipHero GraphQL error.';
                throw new RuntimeException((string) $message);
            }

            $usersBlock = $json['data']['users'] ?? null;
            if (! is_array($usersBlock)) {
                throw new RuntimeException('Unexpected ShipHero response for users query.');
            }

            if ($requestId === null && isset($usersBlock['request_id'])) {
                $requestId = (string) $usersBlock['request_id'];
            }

            $edges = data_get($usersBlock, 'data.edges');
            if (is_array($edges)) {
                foreach ($edges as $edge) {
                    if (! is_array($edge)) {
                        continue;
                    }
                    $node = $edge['node'] ?? null;
                    if (! is_array($node)) {
                        continue;
                    }
                    $rawStores = $node['stores'] ?? [];
                    if (! is_array($rawStores)) {
                        continue;
                    }
                    foreach ($rawStores as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        $normalized = $this->normalizeStoreRow($row);
                        if ($normalized !== null) {
                            $merged[$this->storeDedupeKey($normalized)] = $normalized;
                        }
                    }
                }
            }

            $hasNext = (bool) data_get($usersBlock, 'data.pageInfo.hasNextPage');
            $endCursor = data_get($usersBlock, 'data.pageInfo.endCursor');
            $after = is_string($endCursor) && $endCursor !== '' ? $endCursor : null;
        } while ($hasNext && $after !== null);

        return [
            'stores' => $this->sortStores(array_values($merged)),
            'request_id' => $requestId,
        ];
    }

    /**
     * @return array{stores: list<array<string, mixed>>, request_id: string|null}
     */
    private function fetchStoresViaCustomerToken(ClientAccount $account): array
    {
        $token = trim((string) $account->shiphero_client_refresh_token);
        if ($token === '') {
            throw new RuntimeException('No ShipHero client refresh token on this account.');
        }

        $graphql = <<<'GQL'
query ShipHeroCustomerStoresMe {
  me {
    request_id
    data {
      stores {
        id
        legacy_id
        shop_name
      }
    }
  }
}
GQL;

        $json = $this->client->query($graphql, [], true, [
            ShipHeroClient::OPTION_REFRESH_TOKEN => $token,
        ]);

        if (isset($json['errors']) && is_array($json['errors']) && $json['errors'] !== []) {
            $message = $json['errors'][0]['message'] ?? 'ShipHero GraphQL error.';
            throw new RuntimeException((string) $message);
        }

        $meBlock = $json['data']['me'] ?? null;
        if (! is_array($meBlock)) {
            throw new RuntimeException('Unexpected ShipHero response for me query.');
        }

        $rawStores = data_get($meBlock, 'data.stores');
        if (! is_array($rawStores)) {
            $rawStores = [];
        }

        $stores = [];
        foreach ($rawStores as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->normalizeStoreRow($row);
            if ($normalized !== null) {
                $stores[] = $normalized;
            }
        }

        return [
            'stores' => $this->sortStores($stores),
            'request_id' => isset($meBlock['request_id']) ? (string) $meBlock['request_id'] : null,
        ];
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{stores: list<array<string, mixed>>, request_id: string|null}
     */
    private function fetchStoresViaUserQuery(array $variables): array
    {
        $graphql = <<<'GQL'
query ShipHeroCustomerStores(
  $id: String,
  $customer_account_id: String,
  $email: String,
  $account_pin: String
) {
  user(
    id: $id,
    customer_account_id: $customer_account_id,
    email: $email,
    account_pin: $account_pin
  ) {
    request_id
    complexity
    data {
      stores {
        id
        legacy_id
        shop_name
      }
    }
  }
}
GQL;

        $json = $this->client->query($graphql, $variables);

        if (isset($json['errors']) && is_array($json['errors']) && $json['errors'] !== []) {
            $message = $json['errors'][0]['message'] ?? 'ShipHero GraphQL error.';
            throw new RuntimeException((string) $message);
        }

        $userBlock = $json['data']['user'] ?? null;
        if (! is_array($userBlock)) {
            throw new RuntimeException('Unexpected ShipHero response for user query.');
        }

        $requestId = isset($userBlock['request_id']) ? (string) $userBlock['request_id'] : null;
        $data = $userBlock['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException('ShipHero user query returned no data.');
        }

        $rawStores = $data['stores'] ?? [];
        if (! is_array($rawStores)) {
            $rawStores = [];
        }

        $stores = [];
        foreach ($rawStores as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->normalizeStoreRow($row);
            if ($normalized !== null) {
                $stores[] = $normalized;
            }
        }

        return [
            'stores' => $this->sortStores($stores),
            'request_id' => $requestId,
        ];
    }

    /**
     * ShipHero requires user(id|email|account_pin). customer_account_id only scopes 3PL context.
     *
     * @return list<array<string, string>>
     */
    private function userLookupStrategies(ClientAccount $account, string $customerAccountId): array
    {
        $strategies = [];

        $customerEmail = $this->resolveCustomerEmailFromShipHero($customerAccountId);
        if ($customerEmail !== '') {
            $strategies[] = [
                'email' => $customerEmail,
                'customer_account_id' => $customerAccountId,
            ];
        }

        $email = $this->resolveAccountEmail($account);
        if ($email !== '' && strcasecmp($email, $customerEmail) !== 0) {
            $strategies[] = [
                'email' => $email,
                'customer_account_id' => $customerAccountId,
            ];
        }

        $strategies[] = [
            'id' => $customerAccountId,
            'customer_account_id' => $customerAccountId,
        ];

        return $strategies;
    }

    private function resolveAccountEmail(ClientAccount $account): string
    {
        foreach ([$account->email, $account->notification_email] as $candidate) {
            $email = trim((string) $candidate);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return '';
    }

    private function resolveCustomerEmailFromShipHero(string $customerAccountId): string
    {
        $graphql = <<<'GQL'
query ShipHeroCustomerEmailLookup($first: Int!, $after: String) {
  account {
    data {
      customers(first: $first, after: $after) {
        pageInfo {
          hasNextPage
          endCursor
        }
        edges {
          node {
            id
            legacy_id
            email
          }
        }
      }
    }
  }
}
GQL;

        $needle = strtolower(trim($customerAccountId));
        if ($needle === '') {
            return '';
        }

        $after = null;
        $pages = 0;

        try {
            do {
                $pages++;
                if ($pages > 50) {
                    break;
                }

                $json = $this->client->query($graphql, [
                    'first' => 100,
                    'after' => $after,
                ]);

                if (isset($json['errors']) && is_array($json['errors']) && $json['errors'] !== []) {
                    return '';
                }

                $edges = data_get($json, 'data.account.data.customers.edges');
                if (! is_array($edges)) {
                    return '';
                }

                foreach ($edges as $edge) {
                    if (! is_array($edge)) {
                        continue;
                    }
                    $node = $edge['node'] ?? null;
                    if (! is_array($node)) {
                        continue;
                    }
                    $id = strtolower(trim((string) ($node['id'] ?? '')));
                    $legacyId = strtolower(trim((string) ($node['legacy_id'] ?? '')));
                    if ($id !== $needle && $legacyId !== $needle) {
                        continue;
                    }
                    $email = trim((string) ($node['email'] ?? ''));
                    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        return $email;
                    }
                }

                $hasNext = (bool) data_get($json, 'data.account.data.customers.pageInfo.hasNextPage');
                $endCursor = data_get($json, 'data.account.data.customers.pageInfo.endCursor');
                $after = is_string($endCursor) && $endCursor !== '' ? $endCursor : null;
            } while ($hasNext && $after !== null);
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }

    private function resolveCustomerAccountId(ClientAccount $account): string
    {
        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            throw new RuntimeException('This account has no ShipHero customer account ID.');
        }

        return $customerId;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalizeStoreRow(array $row): ?array
    {
        $shipheroId = trim((string) ($row['id'] ?? ''));
        $legacyRaw = $row['legacy_id'] ?? null;
        $legacyId = $legacyRaw !== null && $legacyRaw !== '' ? (string) $legacyRaw : '';
        $shopName = trim((string) ($row['shop_name'] ?? ''));

        if ($shipheroId === '' && $legacyId === '' && $shopName === '') {
            return null;
        }

        return [
            'shiphero_id' => $shipheroId,
            'legacy_id' => $legacyId,
            'shop_name' => $shopName,
            'settings_url' => $this->buildSettingsUrl($legacyId),
        ];
    }

    /**
     * @param  array<string, mixed>  $store
     */
    private function storeDedupeKey(array $store): string
    {
        $legacyId = trim((string) ($store['legacy_id'] ?? ''));
        if ($legacyId !== '') {
            return 'legacy:'.$legacyId;
        }
        $shipheroId = trim((string) ($store['shiphero_id'] ?? ''));
        if ($shipheroId !== '') {
            return 'id:'.$shipheroId;
        }

        return 'name:'.strtolower(trim((string) ($store['shop_name'] ?? '')));
    }

    /**
     * @param  list<array<string, mixed>>  $stores
     * @return list<array<string, mixed>>
     */
    private function sortStores(array $stores): array
    {
        usort($stores, function (array $a, array $b) {
            return strcasecmp((string) ($a['shop_name'] ?? ''), (string) ($b['shop_name'] ?? ''));
        });

        return array_values($stores);
    }

    private function buildSettingsUrl(string $legacyId): string
    {
        $legacyId = trim($legacyId);
        if ($legacyId === '') {
            return 'https://app.shiphero.com/dashboard/stores';
        }

        return self::SETTINGS_URL_BASE.'?shop='.rawurlencode($legacyId);
    }

    private function cacheKey(int $clientAccountId): string
    {
        return 'shiphero.stores.client_account.'.$clientAccountId;
    }
}
