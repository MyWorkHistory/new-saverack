<?php

namespace App\Services;

use App\Models\ClientAccount;
use Illuminate\Support\Facades\Cache;
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

        foreach ($this->userLookupStrategies($account, $customerAccountId) as $variables) {
            try {
                return $this->fetchStoresViaUserQuery($variables);
            } catch (RuntimeException $e) {
                $lastError = $e;
            }
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

        usort($stores, function (array $a, array $b) {
            return strcasecmp((string) ($a['shop_name'] ?? ''), (string) ($b['shop_name'] ?? ''));
        });

        return [
            'stores' => $stores,
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
        $strategies = [
            [
                'id' => $customerAccountId,
                'customer_account_id' => $customerAccountId,
            ],
        ];

        $email = $this->resolveAccountEmail($account);
        if ($email !== '') {
            $strategies[] = [
                'email' => $email,
                'customer_account_id' => $customerAccountId,
            ];
        }

        $customerEmail = $this->resolveCustomerEmailFromShipHero($customerAccountId);
        if ($customerEmail !== '' && strcasecmp($customerEmail, $email) !== 0) {
            $strategies[] = [
                'email' => $customerEmail,
                'customer_account_id' => $customerAccountId,
            ];
        }

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
query ShipHeroCustomerEmailLookup {
  account {
    data {
      customers(first: 100) {
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

        try {
            $json = $this->client->query($graphql);
        } catch (\Throwable $e) {
            return '';
        }

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
            $id = trim((string) ($node['id'] ?? ''));
            $legacyId = trim((string) ($node['legacy_id'] ?? ''));
            if ($id !== $customerAccountId && $legacyId !== $customerAccountId) {
                continue;
            }
            $email = trim((string) ($node['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
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
