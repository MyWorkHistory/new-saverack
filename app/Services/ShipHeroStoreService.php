<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\ClientAccountShipHeroStoreMeta;
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
            'stores' => $this->enrichStoresWithMeta($account, array_values($stores)),
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

        $normalized = [];
        foreach ($fetched['stores'] as $store) {
            if (! is_array($store)) {
                continue;
            }
            $row = $this->normalizeStoreRow($store);
            if ($row !== null) {
                $normalized[] = $row;
            }
        }
        $normalized = $this->sortStores($this->dedupeStores($normalized));

        $this->seedMetaGuessesFromStores($account, $normalized);

        $payload = [
            'stores' => $normalized,
            'imported_at' => $importedAt,
        ];

        Cache::put($this->cacheKey((int) $account->id), $payload, self::CACHE_TTL_SECONDS);

        return [
            'stores' => $this->enrichStoresWithMeta($account, $normalized),
            'imported_at' => $importedAt,
        ];
    }

    /**
     * Persist CRM store type / shop id override for a cached ShipHero store.
     *
     * @return array{stores: list<array<string, mixed>>, imported_at: string|null}
     */
    public function updateStoreMeta(ClientAccount $account, string $storeKey, ?string $storeType, ?string $shopId): array
    {
        $storeKey = trim($storeKey);
        if ($storeKey === '') {
            throw new RuntimeException('Store key is required.');
        }

        $cached = $this->getRawCachedPayload($account);
        $stores = $cached['stores'];
        $match = null;
        foreach ($stores as $store) {
            if (! is_array($store)) {
                continue;
            }
            if ($this->storeDedupeKey($store) === $storeKey) {
                $match = $store;
                break;
            }
        }
        if ($match === null) {
            throw new RuntimeException('Store not found in CRM cache. Import stores first.');
        }

        $type = $storeType !== null ? strtolower(trim($storeType)) : null;
        if ($type === '') {
            $type = null;
        }
        if ($type !== null && ! ClientAccountShipHeroStoreMeta::isValidType($type)) {
            throw new RuntimeException('Invalid store type.');
        }

        $shop = $shopId !== null ? trim($shopId) : null;
        if ($shop === '') {
            $shop = null;
        }
        if ($shop === null) {
            $legacy = trim((string) ($match['legacy_id'] ?? ''));
            $shop = $legacy !== '' ? $legacy : null;
        }

        ClientAccountShipHeroStoreMeta::query()->updateOrCreate(
            [
                'client_account_id' => (int) $account->id,
                'store_key' => $storeKey,
            ],
            [
                'store_type' => $type,
                'shop_id' => $shop,
            ]
        );

        return $this->getCachedForAccount($account);
    }

    /**
     * Remove a store from the CRM cache only (re-import will restore it from ShipHero).
     *
     * @return array{stores: list<array<string, mixed>>, imported_at: string|null}
     */
    public function deleteStoreFromCache(ClientAccount $account, string $storeKey): array
    {
        $storeKey = trim($storeKey);
        if ($storeKey === '') {
            throw new RuntimeException('Store key is required.');
        }

        $cached = $this->getRawCachedPayload($account);
        $before = count($cached['stores']);
        $stores = array_values(array_filter($cached['stores'], function ($store) use ($storeKey) {
            if (! is_array($store)) {
                return false;
            }

            return $this->storeDedupeKey($store) !== $storeKey;
        }));

        if (count($stores) === $before) {
            throw new RuntimeException('Store not found in CRM cache.');
        }

        $importedAt = $cached['imported_at'];
        Cache::put(
            $this->cacheKey((int) $account->id),
            [
                'stores' => $stores,
                'imported_at' => $importedAt,
            ],
            self::CACHE_TTL_SECONDS
        );

        return [
            'stores' => $this->enrichStoresWithMeta($account, $stores),
            'imported_at' => $importedAt,
        ];
    }

    /**
     * @return array{stores: list<array<string, mixed>>, request_id: string|null}
     */
    public function fetchFromShipHero(ClientAccount $account): array
    {
        $context = $this->resolveCustomerContext($account);
        $lastError = null;
        $bestEmpty = null;

        $attempts = [];

        foreach ($context['customer_account_ids'] as $customerAccountId) {
            $attempts[] = function () use ($customerAccountId) {
                return $this->fetchStoresViaUsersQuery($customerAccountId);
            };
            $attempts[] = function () use ($customerAccountId, $context) {
                return $this->fetchStoresViaUsersPerUserQuery($customerAccountId, $context['user_ids']);
            };
        }

        $attempts[] = function () use ($account) {
            return $this->fetchStoresViaCustomerToken($account);
        };

        foreach ($this->userLookupStrategies($account, $context) as $variables) {
            $attempts[] = function () use ($variables) {
                return $this->fetchStoresViaUserQuery($variables);
            };
        }

        foreach ($context['customer_account_ids'] as $customerAccountId) {
            $attempts[] = function () use ($customerAccountId) {
                return $this->fetchStoresViaOrdersShopNames($customerAccountId);
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
                'shiphero_customer_account_id' => $context['raw'],
                'resolved_customer_account_ids' => $context['customer_account_ids'],
                'resolved_user_ids' => $context['user_ids'],
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
     * @return array{
     *   raw: string,
     *   legacy_id: string|null,
     *   graphql_id: string|null,
     *   email: string|null,
     *   customer_account_ids: list<string>,
     *   user_ids: list<string>
     * }
     */
    private function resolveCustomerContext(ClientAccount $account): array
    {
        $raw = trim((string) $account->shiphero_customer_account_id);
        if ($raw === '') {
            throw new RuntimeException('This account has no ShipHero customer account ID.');
        }

        $legacyId = $this->extractLegacyAccountId($raw);
        $graphqlId = $this->looksLikeGraphqlUuid($raw) ? $raw : null;
        $email = $this->resolveAccountEmail($account) ?: null;
        $userIds = [];

        $customerNode = $this->findCustomerNodeInShipHeroAccount($raw);
        if ($customerNode !== null) {
            $nodeId = trim((string) ($customerNode['id'] ?? ''));
            if ($nodeId !== '') {
                $graphqlId = $nodeId;
            }
            $nodeLegacy = trim((string) ($customerNode['legacy_id'] ?? ''));
            if ($nodeLegacy !== '') {
                $legacyId = $nodeLegacy;
            }
            $nodeEmail = trim((string) ($customerNode['email'] ?? ''));
            if ($nodeEmail !== '' && filter_var($nodeEmail, FILTER_VALIDATE_EMAIL)) {
                $email = $nodeEmail;
            }
        }

        if ($graphqlId === null && $legacyId !== null) {
            $graphqlId = $this->resolveGraphqlIdFromLegacyAccountId($legacyId);
        }

        $customerAccountIds = [];
        foreach ([$raw, $legacyId, $graphqlId] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && ! in_array($candidate, $customerAccountIds, true)) {
                $customerAccountIds[] = $candidate;
            }
        }

        foreach ($customerAccountIds as $customerAccountId) {
            foreach ($this->listUserIdsForCustomerAccount($customerAccountId) as $userId) {
                if (! in_array($userId, $userIds, true)) {
                    $userIds[] = $userId;
                }
            }
        }

        return [
            'raw' => $raw,
            'legacy_id' => $legacyId,
            'graphql_id' => $graphqlId,
            'email' => $email,
            'customer_account_ids' => $customerAccountIds,
            'user_ids' => $userIds,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findCustomerNodeInShipHeroAccount(string $needle): ?array
    {
        $needleLower = strtolower(trim($needle));
        if ($needleLower === '') {
            return null;
        }

        $graphql = <<<'GQL'
query ShipHeroCustomerLookup($first: Int!, $after: String) {
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
            username
          }
        }
      }
    }
  }
}
GQL;

        $after = null;
        $pages = 0;

        try {
            do {
                $pages++;
                if ($pages > 50) {
                    break;
                }

                $variables = ['first' => 100];
                if ($after !== null) {
                    $variables['after'] = $after;
                }

                $json = $this->client->query($graphql, $variables);
                if (isset($json['errors']) && is_array($json['errors']) && $json['errors'] !== []) {
                    return null;
                }

                $edges = data_get($json, 'data.account.data.customers.edges');
                if (! is_array($edges)) {
                    return null;
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
                    $username = strtolower(trim((string) ($node['username'] ?? '')));
                    if ($id === $needleLower || $legacyId === $needleLower || $username === $needleLower) {
                        return $node;
                    }
                }

                $hasNext = (bool) data_get($json, 'data.account.data.customers.pageInfo.hasNextPage');
                $endCursor = data_get($json, 'data.account.data.customers.pageInfo.endCursor');
                $after = is_string($endCursor) && $endCursor !== '' ? $endCursor : null;
            } while ($hasNext && $after !== null);
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private function resolveGraphqlIdFromLegacyAccountId(string $legacyId): ?string
    {
        $legacyId = trim($legacyId);
        if ($legacyId === '' || ! ctype_digit($legacyId)) {
            return null;
        }

        $graphql = <<<'GQL'
query ShipHeroAccountUuid($legacy_id: BigInt!) {
  uuid(legacy_id: $legacy_id, entity: Account) {
    request_id
    data {
      id
      legacy_id
    }
  }
}
GQL;

        try {
            $json = $this->client->query($graphql, ['legacy_id' => $legacyId]);
        } catch (\Throwable $e) {
            return null;
        }

        if (isset($json['errors']) && is_array($json['errors']) && $json['errors'] !== []) {
            return null;
        }

        $id = data_get($json, 'data.uuid.data.id');

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    /**
     * @return list<string>
     */
    private function listUserIdsForCustomerAccount(string $customerAccountId): array
    {
        $graphql = <<<'GQL'
query ShipHeroCustomerUserIds($customer_account_id: String!, $first: Int!, $after: String) {
  users(customer_account_id: $customer_account_id) {
    data(first: $first, after: $after) {
      pageInfo {
        hasNextPage
        endCursor
      }
      edges {
        node {
          id
        }
      }
    }
  }
}
GQL;

        $ids = [];
        $after = null;
        $pages = 0;

        do {
            $pages++;
            if ($pages > 20) {
                break;
            }

            $variables = [
                'customer_account_id' => $customerAccountId,
                'first' => 50,
            ];
            if ($after !== null) {
                $variables['after'] = $after;
            }

            try {
                $json = $this->client->query($graphql, $variables);
            } catch (\Throwable $e) {
                break;
            }

            if (isset($json['errors']) && is_array($json['errors']) && $json['errors'] !== []) {
                break;
            }

            $edges = data_get($json, 'data.users.data.edges');
            if (! is_array($edges)) {
                break;
            }

            foreach ($edges as $edge) {
                if (! is_array($edge)) {
                    continue;
                }
                $id = trim((string) data_get($edge, 'node.id'));
                if ($id !== '' && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }

            $hasNext = (bool) data_get($json, 'data.users.data.pageInfo.hasNextPage');
            $endCursor = data_get($json, 'data.users.data.pageInfo.endCursor');
            $after = is_string($endCursor) && $endCursor !== '' ? $endCursor : null;
        } while ($hasNext && $after !== null);

        return $ids;
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

            $variables = [
                'customer_account_id' => $customerAccountId,
                'first' => 50,
            ];
            if ($after !== null) {
                $variables['after'] = $after;
            }

            $json = $this->client->query($graphql, $variables);

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
     * @param  list<string>  $userIds
     * @return array{stores: list<array<string, mixed>>, request_id: string|null}
     */
    private function fetchStoresViaUsersPerUserQuery(string $customerAccountId, array $userIds): array
    {
        if ($userIds === []) {
            throw new RuntimeException('No ShipHero users found for this customer account.');
        }

        $merged = [];
        $requestId = null;

        foreach ($userIds as $userId) {
            $result = $this->fetchStoresViaUserQuery([
                'id' => $userId,
                'customer_account_id' => $customerAccountId,
            ]);
            if ($requestId === null && ($result['request_id'] ?? null) !== null) {
                $requestId = $result['request_id'];
            }
            foreach ($result['stores'] as $store) {
                $merged[$this->storeDedupeKey($store)] = $store;
            }
        }

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
     * Last-resort: derive store names from recent orders when user.stores is empty in 3PL context.
     *
     * @return array{stores: list<array<string, mixed>>, request_id: string|null}
     */
    private function fetchStoresViaOrdersShopNames(string $customerAccountId): array
    {
        $graphql = <<<'GQL'
query ShipHeroCustomerOrderShopNames($customer_account_id: String!, $first: Int!, $after: String) {
  orders(customer_account_id: $customer_account_id) {
    request_id
    data(first: $first, after: $after) {
      pageInfo {
        hasNextPage
        endCursor
      }
      edges {
        node {
          shop_name
        }
      }
    }
  }
}
GQL;

        $shopNames = [];
        $requestId = null;
        $after = null;
        $pages = 0;

        do {
            $pages++;
            if ($pages > 10) {
                break;
            }

            $variables = [
                'customer_account_id' => $customerAccountId,
                'first' => 100,
            ];
            if ($after !== null) {
                $variables['after'] = $after;
            }

            $json = $this->client->query($graphql, $variables);

            if (isset($json['errors']) && is_array($json['errors']) && $json['errors'] !== []) {
                $message = $json['errors'][0]['message'] ?? 'ShipHero GraphQL error.';
                throw new RuntimeException((string) $message);
            }

            $ordersBlock = $json['data']['orders'] ?? null;
            if (! is_array($ordersBlock)) {
                throw new RuntimeException('Unexpected ShipHero response for orders query.');
            }

            if ($requestId === null && isset($ordersBlock['request_id'])) {
                $requestId = (string) $ordersBlock['request_id'];
            }

            $edges = data_get($ordersBlock, 'data.edges');
            if (is_array($edges)) {
                foreach ($edges as $edge) {
                    if (! is_array($edge)) {
                        continue;
                    }
                    $name = trim((string) data_get($edge, 'node.shop_name'));
                    if ($name !== '') {
                        $shopNames[strtolower($name)] = $name;
                    }
                }
            }

            $hasNext = (bool) data_get($ordersBlock, 'data.pageInfo.hasNextPage');
            $endCursor = data_get($ordersBlock, 'data.pageInfo.endCursor');
            $after = is_string($endCursor) && $endCursor !== '' ? $endCursor : null;
        } while ($hasNext && $after !== null);

        if ($shopNames === []) {
            return [
                'stores' => [],
                'request_id' => $requestId,
            ];
        }

        $stores = [];
        foreach (array_values($shopNames) as $shopName) {
            $row = $this->normalizeStoreRow([
                'id' => '',
                'legacy_id' => '',
                'shop_name' => $shopName,
                'source' => 'orders',
            ]);
            if ($row !== null) {
                $stores[] = $row;
            }
        }

        return [
            'stores' => $this->sortStores($stores),
            'request_id' => $requestId,
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
     * @param  array{
     *   raw: string,
     *   legacy_id: string|null,
     *   graphql_id: string|null,
     *   email: string|null,
     *   customer_account_ids: list<string>,
     *   user_ids: list<string>
     * }  $context
     * @return list<array<string, string>>
     */
    private function userLookupStrategies(ClientAccount $account, array $context): array
    {
        $strategies = [];
        $customerAccountId = $context['customer_account_ids'][0] ?? $context['raw'];

        if (! empty($context['email'])) {
            $strategies[] = [
                'email' => (string) $context['email'],
                'customer_account_id' => $customerAccountId,
            ];
        }

        $accountEmail = $this->resolveAccountEmail($account);
        if ($accountEmail !== '' && strcasecmp($accountEmail, (string) ($context['email'] ?? '')) !== 0) {
            $strategies[] = [
                'email' => $accountEmail,
                'customer_account_id' => $customerAccountId,
            ];
        }

        foreach ($context['user_ids'] as $userId) {
            $strategies[] = [
                'id' => $userId,
                'customer_account_id' => $customerAccountId,
            ];
        }

        foreach ($context['customer_account_ids'] as $candidateId) {
            $strategies[] = [
                'id' => $candidateId,
                'customer_account_id' => $candidateId,
            ];
        }

        $unique = [];
        $seen = [];
        foreach ($strategies as $strategy) {
            $key = json_encode($strategy);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $strategy;
            }
        }

        return $unique;
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

    private function extractLegacyAccountId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return $value;
        }
        if (preg_match('/^Account:(\d+)$/i', $value, $matches) === 1) {
            return $matches[1];
        }
        $decoded = base64_decode($value, true);
        if (is_string($decoded) && $decoded !== '') {
            if (preg_match('/^Account:(\d+)$/i', trim($decoded), $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function looksLikeGraphqlUuid(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || ctype_digit($value)) {
            return false;
        }

        return strlen($value) > 20;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalizeStoreRow(array $row): ?array
    {
        $shipheroId = trim((string) ($row['id'] ?? $row['shiphero_id'] ?? ''));
        $legacyRaw = $row['legacy_id'] ?? null;
        $legacyId = $legacyRaw !== null && $legacyRaw !== '' ? (string) $legacyRaw : '';
        $shopName = trim((string) ($row['shop_name'] ?? ''));

        if ($shipheroId === '' && $legacyId === '' && $shopName === '') {
            return null;
        }

        $out = [
            'shiphero_id' => $shipheroId,
            'legacy_id' => $legacyId,
            'shop_name' => $shopName,
            'store_key' => '',
            'shop_id' => $legacyId !== '' ? $legacyId : null,
            'store_type' => null,
            'settings_url' => null,
        ];
        if (isset($row['source']) && is_string($row['source']) && $row['source'] !== '') {
            $out['source'] = $row['source'];
        }
        $out['store_key'] = $this->storeDedupeKey($out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $store
     */
    public function storeDedupeKey(array $store): string
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
    private function dedupeStores(array $stores): array
    {
        $seen = [];
        $out = [];
        foreach ($stores as $store) {
            $key = $this->storeDedupeKey($store);
            if ($key === 'name:' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $store;
        }

        return $out;
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

    /**
     * @return array{stores: list<array<string, mixed>>, imported_at: string|null}
     */
    private function getRawCachedPayload(ClientAccount $account): array
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

        $normalized = [];
        foreach ($stores as $store) {
            if (! is_array($store)) {
                continue;
            }
            $row = $this->normalizeStoreRow($store);
            if ($row !== null) {
                $normalized[] = $row;
            }
        }

        return [
            'stores' => $normalized,
            'imported_at' => isset($cached['imported_at']) && is_string($cached['imported_at'])
                ? $cached['imported_at']
                : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stores
     * @return list<array<string, mixed>>
     */
    private function enrichStoresWithMeta(ClientAccount $account, array $stores): array
    {
        $metaByKey = ClientAccountShipHeroStoreMeta::query()
            ->where('client_account_id', (int) $account->id)
            ->get()
            ->keyBy('store_key');

        $out = [];
        foreach ($stores as $store) {
            if (! is_array($store)) {
                continue;
            }
            $row = $this->normalizeStoreRow($store);
            if ($row === null) {
                continue;
            }

            $key = $row['store_key'];
            /** @var ClientAccountShipHeroStoreMeta|null $meta */
            $meta = $metaByKey->get($key);
            $type = $meta !== null ? (string) ($meta->store_type ?? '') : '';
            $type = $type !== '' ? strtolower($type) : null;
            $shopId = $meta !== null && trim((string) ($meta->shop_id ?? '')) !== ''
                ? trim((string) $meta->shop_id)
                : (trim((string) ($row['legacy_id'] ?? '')) !== '' ? trim((string) $row['legacy_id']) : null);

            $row['store_type'] = $type;
            $row['shop_id'] = $shopId;
            $row['settings_url'] = $this->buildSettingsUrl($type, $shopId);
            $out[] = $row;
        }

        return $this->sortStores($out);
    }

    /**
     * Seed guessed store types into meta for rows that have no user-set type yet.
     *
     * @param  list<array<string, mixed>>  $stores
     */
    private function seedMetaGuessesFromStores(ClientAccount $account, array $stores): void
    {
        $existing = ClientAccountShipHeroStoreMeta::query()
            ->where('client_account_id', (int) $account->id)
            ->get()
            ->keyBy('store_key');

        foreach ($stores as $store) {
            $key = $this->storeDedupeKey($store);
            /** @var ClientAccountShipHeroStoreMeta|null $meta */
            $meta = $existing->get($key);
            if ($meta !== null && ClientAccountShipHeroStoreMeta::isValidType((string) ($meta->store_type ?? ''))) {
                continue;
            }

            $guess = $this->guessStoreType((string) ($store['shop_name'] ?? ''));
            if ($guess === null) {
                continue;
            }

            $shopId = trim((string) ($store['legacy_id'] ?? ''));
            ClientAccountShipHeroStoreMeta::query()->updateOrCreate(
                [
                    'client_account_id' => (int) $account->id,
                    'store_key' => $key,
                ],
                [
                    'store_type' => $guess,
                    'shop_id' => $shopId !== '' ? $shopId : ($meta !== null ? $meta->shop_id : null),
                ]
            );
        }
    }

    private function guessStoreType(string $shopName): ?string
    {
        $name = strtolower(trim($shopName));
        if ($name === '') {
            return null;
        }
        if (str_contains($name, 'myshopify.com') || str_contains($name, 'shopify')) {
            return ClientAccountShipHeroStoreMeta::TYPE_SHOPIFY;
        }
        if (str_contains($name, 'amazon')) {
            return ClientAccountShipHeroStoreMeta::TYPE_AMAZON;
        }
        if (str_contains($name, 'woocommerce') || str_contains($name, 'woo ')) {
            return ClientAccountShipHeroStoreMeta::TYPE_WOOCOMMERCE;
        }
        if (str_contains($name, 'walmart')) {
            return ClientAccountShipHeroStoreMeta::TYPE_WALMART;
        }
        if (str_contains($name, 'etsy')) {
            return ClientAccountShipHeroStoreMeta::TYPE_ETSY;
        }
        if (str_contains($name, 'tiktok')) {
            return ClientAccountShipHeroStoreMeta::TYPE_TIKTOK;
        }
        if (str_contains($name, 'bigcommerce')) {
            return ClientAccountShipHeroStoreMeta::TYPE_BIGCOMMERCE;
        }

        return null;
    }

    public function buildSettingsUrl(?string $type, ?string $shopId): ?string
    {
        $type = $type !== null ? strtolower(trim($type)) : '';
        $shopId = $shopId !== null ? trim($shopId) : '';

        if ($shopId === '') {
            return null;
        }

        // Public API stores have no ShipHero settings deep-link.
        if ($type === ClientAccountShipHeroStoreMeta::TYPE_API) {
            return null;
        }

        if ($type !== '' && ClientAccountShipHeroStoreMeta::isValidType($type)) {
            return self::SETTINGS_URL_BASE
                .'?type='.rawurlencode($type)
                .'&shop='.rawurlencode($shopId);
        }

        // Shop ID alone still opens settings (type can be set later via Edit Type).
        return self::SETTINGS_URL_BASE.'?shop='.rawurlencode($shopId);
    }

    private function cacheKey(int $clientAccountId): string
    {
        return 'shiphero.stores.client_account.'.$clientAccountId;
    }
}
