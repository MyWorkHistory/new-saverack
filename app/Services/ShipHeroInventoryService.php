<?php

namespace App\Services;

use App\Models\ShipHeroInventoryProductDetailCache;
use App\Models\ShipHeroInventoryProductIndex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShipHeroInventoryService
{
    /** @var ShipHeroClient */
    protected $client;

    public function __construct(ShipHeroClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return list<array{id: string, legacy_id: int|null, identifier: string|null, label: string}>
     */
    public function listWarehouses(): array
    {
        return Cache::remember('shiphero.warehouses', now()->addHour(), function () {
            $graphql = <<<'GQL'
query ShipHeroWarehouses {
  account {
    data {
      warehouses {
        id
        legacy_id
        identifier
        address {
          name
        }
      }
    }
  }
}
GQL;

            $json = $this->client->query($graphql);
            $rows = data_get($json, 'data.account.data.warehouses');
            if (! is_array($rows)) {
                return [];
            }

            $out = [];
            foreach ($rows as $w) {
                if (! is_array($w)) {
                    continue;
                }
                $id = isset($w['id']) && is_string($w['id']) ? $w['id'] : '';
                if ($id === '') {
                    continue;
                }
                $identifier = isset($w['identifier']) && is_string($w['identifier']) ? $w['identifier'] : null;
                $addrName = data_get($w, 'address.name');
                $addrName = is_string($addrName) ? $addrName : null;
                $label = $identifier ?? $addrName ?? $id;
                $legacy = $w['legacy_id'] ?? null;
                $out[] = [
                    'id' => $id,
                    'legacy_id' => is_int($legacy) ? $legacy : (is_numeric($legacy) ? (int) $legacy : null),
                    'identifier' => $identifier,
                    'label' => $label,
                ];
            }

            return $out;
        });
    }

    /**
     * @return array<string, mixed>|null Normalized product payload or null if not found
     */
    /**
     * @param  string|null  $customerAccountId  ShipHero GraphQL `customer_account_id` (3PL), or null for brand-level
     */
    public function searchProduct(string $term, ?string $warehouseId = null, ?string $customerAccountId = null): ?array
    {
        $term = trim($term);
        if ($term === '') {
            return null;
        }

        $barcodeTerm = $this->normalizeBarcodeTerm($term);

        if ($this->looksLikeBarcode($term)) {
            $data = $this->safeProductFetch('shiphero.inventory.search.by_barcode_failed', $term, $customerAccountId, function () use ($barcodeTerm, $customerAccountId) {
                return $this->fetchProductByBarcode($barcodeTerm, $customerAccountId);
            });
            if ($data === null) {
                $data = $this->safeProductFetch('shiphero.inventory.search.by_barcode_basic_failed', $term, $customerAccountId, function () use ($barcodeTerm, $customerAccountId) {
                    return $this->fetchProductByBarcodeBasic($barcodeTerm, $customerAccountId);
                });
            }
            if ($data === null) {
                $data = $this->safeProductFetch('shiphero.inventory.search.by_sku_after_barcode_failed', $term, $customerAccountId, function () use ($term, $customerAccountId) {
                    return $this->fetchProductBySku($term, $customerAccountId);
                });
            }
            if ($data === null) {
                $data = $this->safeProductFetch('shiphero.inventory.search.by_sku_basic_after_barcode_failed', $term, $customerAccountId, function () use ($term, $customerAccountId) {
                    return $this->fetchProductBySkuBasic($term, $customerAccountId);
                });
            }
        } else {
            $data = $this->safeProductFetch('shiphero.inventory.search.by_sku_failed', $term, $customerAccountId, function () use ($term, $customerAccountId) {
                return $this->fetchProductBySku($term, $customerAccountId);
            });
            if ($data === null) {
                $data = $this->safeProductFetch('shiphero.inventory.search.by_sku_basic_failed', $term, $customerAccountId, function () use ($term, $customerAccountId) {
                    return $this->fetchProductBySkuBasic($term, $customerAccountId);
                });
            }
            if ($data === null) {
                $data = $this->safeProductFetch('shiphero.inventory.search.by_barcode_after_sku_failed', $term, $customerAccountId, function () use ($term, $customerAccountId) {
                    return $this->fetchProductByBarcode($term, $customerAccountId);
                });
            }
            if ($data === null) {
                $data = $this->safeProductFetch('shiphero.inventory.search.by_barcode_basic_after_sku_failed', $term, $customerAccountId, function () use ($term, $customerAccountId) {
                    return $this->fetchProductByBarcodeBasic($term, $customerAccountId);
                });
            }
        }

        if ($data === null) {
            return null;
        }

        $id = isset($data['id']) && is_string($data['id']) ? trim($data['id']) : '';
        if ($id !== '') {
            try {
                $byId = $this->fetchProductById($id, $customerAccountId);
                if (is_array($byId)) {
                    $data = array_merge($data, $byId);
                }
            } catch (\Throwable $e) {
                // Keep SKU/barcode flow resilient if by-id detail query fails.
            }
        }

        return $this->normalizeProduct($data, $warehouseId);
    }

    /**
     * Portal inventory list: one row per warehouse_product, with product kit/active flags.
     *
     * @param  'all'|'yes'|'no'  $kitsFilter
     * @param  'active'|'inactive'|'all'  $activeStatus
     * @return array{rows: list<array<string,mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null, next_search_skip?: int}}
     */
    public function listInventoryRows(
        ?string $customerAccountId = null,
        int $first = 100,
        ?string $after = null,
        string $kitsFilter = 'all',
        string $activeStatus = 'active',
        ?string $searchQuery = null,
        int $searchSkip = 0,
        ?int $clientAccountId = null,
        bool $backorderOnly = false,
        bool $refresh = false
    ): array {
        $first = max(1, min(200, $first));
        $after = is_string($after) && trim($after) !== '' ? trim($after) : null;
        $kitsFilter = in_array($kitsFilter, ['all', 'yes', 'no'], true) ? $kitsFilter : 'all';
        $activeStatus = in_array($activeStatus, ['active', 'inactive', 'all'], true) ? $activeStatus : 'active';
        $searchQuery = is_string($searchQuery) ? trim($searchQuery) : '';
        $searchSkip = max(0, $searchSkip);

        if ($refresh && $after === null) {
            $this->clearInventoryIndexForAccount($clientAccountId, $customerAccountId);
        }

        $graphql = <<<'GQL'
query ShipHeroInventoryRows($customer_account_id: String, $first: Int!, $after: String) {
  products(customer_account_id: $customer_account_id) {
    data(first: $first, after: $after) {
      pageInfo {
        hasNextPage
        endCursor
      }
      edges {
        node {
          id
          sku
          name
          barcode
          active
          kit
          kit_build
          images {
            src
            position
          }
          warehouse_products {
            warehouse_id
            on_hand
            allocated
            available
            backorder
            active
          }
        }
      }
    }
  }
}
GQL;

        if ($searchQuery !== '') {
            $direct = $this->listInventoryRowsTryDirectProductSearch(
                $customerAccountId,
                $kitsFilter,
                $activeStatus,
                $first,
                $after,
                $searchQuery,
                $searchSkip,
                $clientAccountId,
                $backorderOnly
            );
            if ($direct !== null) {
                return $direct;
            }

            $indexed = $this->inventoryListUseIndex($refresh, $backorderOnly)
                ? $this->searchInventoryIndexRows(
                $clientAccountId,
                $customerAccountId,
                $first,
                $after,
                $searchQuery,
                $searchSkip,
                $kitsFilter,
                $activeStatus,
                $backorderOnly
            )
                : null;
            if ($indexed !== null) {
                return $indexed;
            }

            return $this->listInventoryRowsSearch(
                $graphql,
                $customerAccountId,
                $kitsFilter,
                $activeStatus,
                $first,
                $after,
                $searchQuery,
                $searchSkip,
                $clientAccountId,
                $backorderOnly
            );
        }

        $indexed = $this->inventoryListUseIndex($refresh, $backorderOnly)
            ? $this->searchInventoryIndexRows(
            $clientAccountId,
            $customerAccountId,
            $first,
            $after,
            null,
            0,
            $kitsFilter,
            $activeStatus,
            $backorderOnly
        )
            : null;
        if ($indexed !== null) {
            return $indexed;
        }

        if ($backorderOnly) {
            return $this->listInventoryRowsSearch(
                $graphql,
                $customerAccountId,
                $kitsFilter,
                $activeStatus,
                $first,
                $after,
                '',
                0,
                $clientAccountId,
                true
            );
        }

        $vars = array_merge(
            ['first' => $first, 'after' => $after],
            $this->customerAccountVariables($customerAccountId)
        );
        $json = $this->client->query($graphql, $vars);
        $edges = data_get($json, 'data.products.data.edges');
        $pageInfo = data_get($json, 'data.products.data.pageInfo');
        $hasNext = is_array($pageInfo) && (($pageInfo['hasNextPage'] ?? false) === true);
        $endCursor = is_array($pageInfo) && isset($pageInfo['endCursor']) && is_string($pageInfo['endCursor'])
            ? $pageInfo['endCursor']
            : null;
        if ($endCursor === '') {
            $endCursor = null;
        }
        if (! is_array($edges)) {
            return [
                'rows' => [],
                'page_info' => [
                    'has_next_page' => $hasNext,
                    'end_cursor' => $endCursor,
                ],
            ];
        }
        $rows = $this->expandInventoryProductEdgesToRows($edges, $kitsFilter, $activeStatus);
        if ($backorderOnly) {
            $rows = $this->filterOversoldInventoryRows($rows);
        }
        $this->upsertInventoryIndexRows($clientAccountId, $customerAccountId, $rows);

        return [
            'rows' => $rows,
            'page_info' => [
                'has_next_page' => $hasNext,
                'end_cursor' => $endCursor,
            ],
        ];
    }

    /**
     * Use the local index whenever we are not actively rebuilding it via refresh=1.
     * Oversold rows are filtered from the index (backorder &gt; 0); live scan is fallback when index is empty.
     */
    private function inventoryListUseIndex(bool $refresh, bool $backorderOnly): bool
    {
        return ! $refresh;
    }

    private function clearInventoryIndexForAccount(?int $clientAccountId, ?string $customerAccountId): void
    {
        $query = ShipHeroInventoryProductIndex::query();
        $scoped = $this->applyInventoryIndexAccountScope($query, $clientAccountId, $customerAccountId);
        if ($scoped === null) {
            return;
        }
        $scoped->delete();

        if ($clientAccountId !== null && $clientAccountId > 0) {
            ShipHeroInventoryProductDetailCache::query()
                ->where('client_account_id', $clientAccountId)
                ->delete();
        }
    }

    private function normalizeInventoryIndexSearchValue($value): string
    {
        return mb_strtolower(trim((string) ($value ?? '')));
    }

    private function applyInventoryIndexAccountScope($query, ?int $clientAccountId, ?string $customerAccountId)
    {
        if ($clientAccountId !== null && $clientAccountId > 0) {
            return $query->where('client_account_id', $clientAccountId);
        }

        $customer = is_string($customerAccountId) ? trim($customerAccountId) : '';
        if ($customer !== '') {
            return $query->where('shiphero_customer_account_id', $customer);
        }

        return null;
    }

    private function inventoryIndexRowToListRow(ShipHeroInventoryProductIndex $row): array
    {
        return [
            'product_id' => $row->shiphero_product_id,
            'sku' => $row->sku,
            'name' => $row->name ?? '',
            'barcode' => $row->barcode,
            'image_url' => $row->image_url,
            'product_active' => (bool) $row->product_active,
            'kit' => (bool) $row->kit,
            'kit_build' => (bool) $row->kit_build,
            'warehouse_id' => $row->warehouse_id,
            'warehouse_active' => (bool) $row->warehouse_active,
            'on_hand' => (float) $row->on_hand,
            'allocated' => (float) $row->allocated,
            'backorder' => (float) $row->backorder,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertInventoryIndexRows(?int $clientAccountId, ?string $customerAccountId, array $rows): void
    {
        if (($clientAccountId === null || $clientAccountId <= 0) && trim((string) $customerAccountId) === '') {
            return;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $warehouseId = isset($row['warehouse_id']) && $row['warehouse_id'] !== null
                ? trim((string) $row['warehouse_id'])
                : null;
            if ($warehouseId === '') {
                $warehouseId = null;
            }

            ShipHeroInventoryProductIndex::query()->updateOrCreate(
                [
                    'client_account_id' => $clientAccountId,
                    'shiphero_customer_account_id' => trim((string) $customerAccountId) ?: null,
                    'sku' => $sku,
                    'warehouse_id' => $warehouseId,
                ],
                [
                    'shiphero_product_id' => trim((string) ($row['product_id'] ?? '')) ?: null,
                    'sku_search' => $this->normalizeInventoryIndexSearchValue($sku),
                    'name' => isset($row['name']) ? (string) $row['name'] : '',
                    'name_search' => $this->normalizeInventoryIndexSearchValue($row['name'] ?? ''),
                    'barcode' => trim((string) ($row['barcode'] ?? '')) ?: null,
                    'barcode_search' => $this->normalizeInventoryIndexSearchValue($row['barcode'] ?? ''),
                    'image_url' => trim((string) ($row['image_url'] ?? '')) ?: null,
                    'product_active' => (bool) ($row['product_active'] ?? true),
                    'kit' => (bool) ($row['kit'] ?? false),
                    'kit_build' => (bool) ($row['kit_build'] ?? false),
                    'warehouse_active' => (bool) ($row['warehouse_active'] ?? true),
                    'on_hand' => (float) ($row['on_hand'] ?? 0),
                    'allocated' => (float) ($row['allocated'] ?? 0),
                    'backorder' => (float) ($row['backorder'] ?? 0),
                    'synced_at' => now(),
                ]
            );
        }
    }

    /**
     * @return array{rows: list<array<string,mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null, next_search_skip?: int}}|null
     */
    private function searchInventoryIndexRows(
        ?int $clientAccountId,
        ?string $customerAccountId,
        int $first,
        ?string $after,
        ?string $searchQuery,
        int $searchSkip,
        string $kitsFilter,
        string $activeStatus,
        bool $backorderOnly
    ): ?array {
        $query = ShipHeroInventoryProductIndex::query();
        $query = $this->applyInventoryIndexAccountScope($query, $clientAccountId, $customerAccountId);
        if ($query === null) {
            return null;
        }

        if ($activeStatus === 'active') {
            $query->where('product_active', true);
        } elseif ($activeStatus === 'inactive') {
            $query->where('product_active', false);
        }
        if ($kitsFilter === 'yes') {
            $query->where(function ($q) {
                $q->where('kit', true)->orWhere('kit_build', true);
            });
        } elseif ($kitsFilter === 'no') {
            $query->where('kit', false)->where('kit_build', false);
        }
        if ($backorderOnly) {
            $query->where('backorder', '>', 0);
        }

        $term = $this->normalizeInventoryIndexSearchValue($searchQuery ?? '');
        if ($term !== '') {
            if ($after !== null) {
                return null;
            }
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('sku_search', 'like', $like)
                    ->orWhere('name_search', 'like', $like)
                    ->orWhere('barcode_search', 'like', $like);
            });
            $query->orderByRaw(
                'CASE WHEN sku_search = ? THEN 0 WHEN barcode_search = ? THEN 1 WHEN sku_search LIKE ? THEN 2 WHEN name_search LIKE ? THEN 3 ELSE 4 END',
                [$term, $term, $term.'%', $term.'%']
            );
            $offset = max(0, $searchSkip);
        } else {
            $offset = 0;
            if ($after !== null && preg_match('/^idx:(\d+)$/', $after, $m)) {
                $offset = max(0, (int) $m[1]);
            } elseif ($after !== null) {
                return null;
            }
            if ((clone $query)->count() === 0) {
                return null;
            }
        }

        $first = max(1, min(200, $first));
        $total = (clone $query)->count();
        if ($total === 0) {
            return null;
        }

        $items = $query
            ->orderByDesc('on_hand')
            ->orderBy('sku')
            ->orderBy('warehouse_id')
            ->skip($offset)
            ->take($first)
            ->get();
        $rows = $items->map(function (ShipHeroInventoryProductIndex $row) {
            return $this->inventoryIndexRowToListRow($row);
        })->values()->all();
        if ($backorderOnly) {
            $rows = $this->filterOversoldInventoryRows($rows);
        }
        $next = $offset + count($rows);

        return [
            'rows' => $rows,
            'page_info' => [
                'has_next_page' => $next < $total,
                'end_cursor' => $term === '' ? ($next < $total ? 'idx:'.$next : null) : null,
                'next_search_skip' => $term !== '' ? $next : null,
            ],
        ];
    }

    /**
     * Portal out-of-stock list: only rows with oversold quantity (ShipHero backorder) &gt; 0.
     *
     * @param  array<string, mixed>  $row
     */
    private function isOversoldInventoryRow(array $row): bool
    {
        return (float) ($row['backorder'] ?? 0) > 0;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterOversoldInventoryRows(array $rows): array
    {
        return array_values(array_filter($rows, function ($row) {
            return is_array($row) && $this->isOversoldInventoryRow($row);
        }));
    }

    /** Single-token searches: use ShipHero product(sku|barcode); multi-word/name search keeps paginated scan. */
    private function inventoryListSearchEligibleForDirectProductLookup(string $searchQuery): bool
    {
        $q = trim($searchQuery);
        $len = strlen($q);
        if ($len < 2 || $len > 255) {
            return false;
        }
        // Keep paginated substring search for phrases (likely title match).
        if (preg_match('/\s/u', $q)) {
            return false;
        }

        return (bool) preg_match('#^[A-Za-z0-9.\/_-]+$#u', $q);
    }

    /**
     * Resolve search via ShipHero single-product lookups (one round-trip) when possible.
     *
     * @return array{rows: list<array<string,mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null, next_search_skip: int}}|null Null falls back to {@see listInventoryRowsSearch}
     */
    private function listInventoryRowsTryDirectProductSearch(
        ?string $customerAccountId,
        string $kitsFilter,
        string $activeStatus,
        int $desired,
        ?string $after,
        string $searchQuery,
        int $searchSkip,
        ?int $clientAccountId,
        bool $backorderOnly
    ): ?array {
        if ($after !== null || ! $this->inventoryListSearchEligibleForDirectProductLookup($searchQuery)) {
            return null;
        }
        $term = trim($searchQuery);

        $data = null;
        $relaxedSubstring = false;
        try {
            $data = $this->fetchProductBySku($term, $customerAccountId);
        } catch (\Throwable $e) {
            $data = null;
        }
            if ($data === null) {
            try {
                $data = $this->fetchProductByBarcode($term, $customerAccountId);
                $relaxedSubstring = true;
            } catch (\Throwable $e) {
                $data = null;
            }
        }

        // No ShipHero hit — try slow scan so partial SKU / fuzzy still works against products() pages.
        if ($data === null || $data === []) {
            return null;
        }

        $edges = [['node' => $data]];
        $expanded = $this->expandInventoryProductEdgesToRows($edges, $kitsFilter, $activeStatus);
        if ($backorderOnly) {
            $expanded = $this->filterOversoldInventoryRows($expanded);
        }
        $this->upsertInventoryIndexRows($clientAccountId, $customerAccountId, $expanded);

        if ($expanded === []) {
            return null;
        }

        $qLower = mb_strtolower($term);
        $matchingAll = [];
        foreach ($expanded as $r) {
            if ($relaxedSubstring) {
                $matchingAll[] = $r;

                continue;
            }
            $skuL = mb_strtolower((string) ($r['sku'] ?? ''));
            $nameL = mb_strtolower((string) ($r['name'] ?? ''));
            if (mb_strpos($skuL, $qLower) === false && mb_strpos($nameL, $qLower) === false) {
                continue;
            }
            $matchingAll[] = $r;
        }

        $totalMatching = count($matchingAll);
        if ($totalMatching === 0) {
            return null;
        }

        $desired = max(1, min(200, $desired));
        $searchSkip = max(0, $searchSkip);
        $pageRows = array_slice($matchingAll, $searchSkip, $desired);
        $delivered = count($pageRows);
        $nextSearchSkip = $searchSkip + $delivered;
        $hasMore = $nextSearchSkip < $totalMatching;

        return [
            'rows' => $pageRows,
            'page_info' => [
                'has_next_page' => $hasMore,
                'end_cursor' => null,
                'next_search_skip' => $nextSearchSkip,
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string,mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null, next_search_skip: int}}
     */
    private function listInventoryRowsSearch(
        string $graphql,
        ?string $customerAccountId,
        string $kitsFilter,
        string $activeStatus,
        int $desired,
        ?string $after,
        string $searchQuery,
        int $searchSkip,
        ?int $clientAccountId,
        bool $backorderOnly
    ): array {
        $qLower = mb_strtolower($searchQuery);
        $matchSkip = $after === null ? $searchSkip : 0;
        $output = [];
        $graphqlAfter = $after;
        $resumeCursor = null;
        $lastFetchHadNext = false;
        $iterations = 0;
        $innerFirst = min(100, max(40, $desired * 3));
        $maxIterations = $backorderOnly ? 25 : 1;

        while ($iterations < $maxIterations) {
            $iterations++;
            $vars = array_merge(
                ['first' => $innerFirst, 'after' => $graphqlAfter],
                $this->customerAccountVariables($customerAccountId)
            );
            $json = $this->client->query($graphql, $vars);
            $edges = data_get($json, 'data.products.data.edges');
            $pageInfo = data_get($json, 'data.products.data.pageInfo');
            $lastFetchHadNext = is_array($pageInfo) && (($pageInfo['hasNextPage'] ?? false) === true);
            $endCursor = is_array($pageInfo) && isset($pageInfo['endCursor']) && is_string($pageInfo['endCursor'])
                ? $pageInfo['endCursor']
                : null;
            if ($endCursor === '') {
                $endCursor = null;
            }
            if (! is_array($edges)) {
                break;
            }
            $pageRows = $this->expandInventoryProductEdgesToRows($edges, $kitsFilter, $activeStatus);
            $this->upsertInventoryIndexRows($clientAccountId, $customerAccountId, $pageRows);
            if ($backorderOnly) {
                $pageRows = $this->filterOversoldInventoryRows($pageRows);
            }

            foreach ($pageRows as $r) {
                $skuL = mb_strtolower((string) ($r['sku'] ?? ''));
                $nameL = mb_strtolower((string) ($r['name'] ?? ''));
                if ($qLower !== '' && mb_strpos($skuL, $qLower) === false && mb_strpos($nameL, $qLower) === false) {
                    continue;
                }
                if ($matchSkip > 0) {
                    $matchSkip--;

                    continue;
                }
                $output[] = $r;
            }
            $graphqlAfter = $endCursor;
            $resumeCursor = $endCursor;
            if (! $lastFetchHadNext || $graphqlAfter === null) {
                break;
            }
            if ($backorderOnly && count($output) >= $desired) {
                break;
            }
        }

        $delivered = count($output);
        $nextSearchSkip = $searchSkip + $delivered;
        $hasMore = $lastFetchHadNext && $resumeCursor !== null;

        return [
            'rows' => $output,
            'page_info' => [
                'has_next_page' => $hasMore,
                'end_cursor' => $resumeCursor,
                'next_search_skip' => $nextSearchSkip,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @param  'all'|'yes'|'no'  $kitsFilter
     * @param  'active'|'inactive'|'all'  $activeStatus
     * @return list<array<string, mixed>>
     */
    private function expandInventoryProductEdgesToRows(array $edges, string $kitsFilter, string $activeStatus): array
    {
        $rows = [];
        foreach ($edges as $edge) {
            $node = is_array($edge['node'] ?? null) ? $edge['node'] : null;
            if (! $node) {
                continue;
            }
            $productActive = $node['active'] ?? null;
            $isInactiveProduct = $productActive === false;
            $isActiveProduct = ! $isInactiveProduct;

            if ($activeStatus === 'active' && ! $isActiveProduct) {
                continue;
            }
            if ($activeStatus === 'inactive' && ! $isInactiveProduct) {
                continue;
            }

            $isKit = ($node['kit'] ?? false) === true || ($node['kit_build'] ?? false) === true;
            if ($kitsFilter === 'yes' && ! $isKit) {
                continue;
            }
            if ($kitsFilter === 'no' && $isKit) {
                continue;
            }

            $productId = isset($node['id']) && is_string($node['id']) ? trim($node['id']) : '';
            $imageUrl = null;
            $images = is_array($node['images'] ?? null) ? $node['images'] : [];
            $bestPos = PHP_INT_MAX;
            foreach ($images as $img) {
                if (! is_array($img)) {
                    continue;
                }
                $src = trim((string) ($img['src'] ?? ''));
                if ($src === '') {
                    continue;
                }
                $pos = isset($img['position']) && is_numeric($img['position']) ? (int) $img['position'] : 999999;
                if ($imageUrl === null || $pos < $bestPos) {
                    $imageUrl = $src;
                    $bestPos = $pos;
                }
            }
            $wps = is_array($node['warehouse_products'] ?? null) ? $node['warehouse_products'] : [];
            $baseRow = [
                'product_id' => $productId,
                'sku' => (string) ($node['sku'] ?? ''),
                'name' => (string) ($node['name'] ?? ''),
                'barcode' => isset($node['barcode']) && is_string($node['barcode']) ? trim($node['barcode']) : null,
                'image_url' => $imageUrl,
                'product_active' => $isActiveProduct,
                'kit' => $isKit,
                'kit_build' => ($node['kit_build'] ?? false) === true,
            ];
            if ($wps === []) {
                $rows[] = array_merge($baseRow, [
                    'warehouse_id' => null,
                    'warehouse_active' => true,
                    'on_hand' => 0,
                    'allocated' => 0,
                    'available' => 0,
                    'backorder' => 0,
                ]);

                continue;
            }
            foreach ($wps as $wp) {
                if (! is_array($wp)) {
                    continue;
                }
                $wpActive = $wp['active'] ?? null;
                $onHand = (float) ($wp['on_hand'] ?? 0);
                $allocated = (float) ($wp['allocated'] ?? 0);
                $available = array_key_exists('available', $wp)
                    ? (float) $wp['available']
                    : max(0, $onHand - $allocated);
                $rows[] = array_merge($baseRow, [
                    'warehouse_id' => (string) ($wp['warehouse_id'] ?? ''),
                    'warehouse_active' => $wpActive !== false,
                    'on_hand' => $onHand,
                    'allocated' => $allocated,
                    'available' => $available,
                    'backorder' => (float) ($wp['backorder'] ?? 0),
                ]);
            }
        }

        return $rows;
    }


    /**
     * Set warehouse-level active flag (ShipHero warehouse_product_update).
     *
     * @throws RuntimeException on GraphQL / validation failure
     */
    public function setWarehouseProductActive(
        string $customerAccountId,
        string $sku,
        string $warehouseId,
        bool $active
    ): void {
        $sku = trim($sku);
        $warehouseId = trim($warehouseId);
        if ($sku === '' || $warehouseId === '') {
            throw new RuntimeException('SKU and warehouse_id are required to update warehouse product status.');
        }
        $graphql = <<<'GQL'
mutation ShipHeroWarehouseProductUpdate($data: UpdateWarehouseProductInput!) {
  warehouse_product_update(data: $data) {
    request_id
    complexity
    warehouse_product {
      sku
      warehouse_id
      active
    }
  }
}
GQL;
        $data = [
            'customer_account_id' => $customerAccountId,
            'sku' => $sku,
            'warehouse_id' => $warehouseId,
            'active' => $active,
        ];
        $json = $this->client->query($graphql, ['data' => $data], true, [
            ShipHeroClient::OPTION_GRAPHQL_SUCCESS_FIELD => 'warehouse_product_update',
        ]);
        $wp = data_get($json, 'data.warehouse_product_update.warehouse_product');
        if (! is_array($wp)) {
            $errs = $json['errors'] ?? [];
            $msg = is_array($errs) && isset($errs[0]['message']) ? (string) $errs[0]['message'] : 'ShipHero did not return warehouse_product after update.';

            throw new RuntimeException($msg);
        }
    }

    /**
     * @param  list<array{sku: string, warehouse_id: string}>  $items
     * @return array{updated: int, errors: list<array{sku: string, warehouse_id: string, message: string}>}
     */
    public function bulkSetWarehouseProductActive(string $customerAccountId, bool $active, array $items): array
    {
        $updated = 0;
        $errors = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $sku = isset($item['sku']) ? trim((string) $item['sku']) : '';
            $wid = isset($item['warehouse_id']) ? trim((string) $item['warehouse_id']) : '';
            if ($sku === '' || $wid === '') {
                $errors[] = ['sku' => $sku, 'warehouse_id' => $wid, 'message' => 'Missing sku or warehouse_id.'];

                continue;
            }
            try {
                $this->setWarehouseProductActive($customerAccountId, $sku, $wid, $active);
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = ['sku' => $sku, 'warehouse_id' => $wid, 'message' => $e->getMessage()];
            }
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * Set or add a product image in ShipHero (product_update). ShipHero may append images rather than replace.
     *
     * @return array{id: string, sku: string, image_url: string|null}
     *
     * @throws RuntimeException
     */
    public function updateProductImage(string $customerAccountId, string $sku, string $imageUrl): array
    {
        $customerAccountId = trim($customerAccountId);
        $sku = trim($sku);
        $imageUrl = trim($imageUrl);
        if ($customerAccountId === '' || $sku === '' || $imageUrl === '') {
            throw new RuntimeException('Customer account, SKU, and image URL are required to update a product image in ShipHero.');
        }

        $graphql = <<<'GQL'
mutation ShipHeroProductUpdateImage($data: UpdateProductInput!) {
  product_update(data: $data) {
    request_id
    complexity
    product {
      id
      legacy_id
      sku
      thumbnail
      large_thumbnail
      images {
        src
        position
      }
    }
  }
}
GQL;
        $data = [
            'customer_account_id' => $customerAccountId,
            'sku' => $sku,
            'images' => [
                ['src' => $imageUrl, 'position' => 1],
            ],
        ];
        $json = $this->client->query($graphql, ['data' => $data], true, [
            ShipHeroClient::OPTION_GRAPHQL_SUCCESS_FIELD => 'product_update',
        ]);
        $product = data_get($json, 'data.product_update.product');
        if (! is_array($product)) {
            $errs = $json['errors'] ?? [];
            $msg = is_array($errs) && isset($errs[0]['message'])
                ? (string) $errs[0]['message']
                : 'ShipHero did not return a product after image update.';

            throw new RuntimeException($msg);
        }

        $id = isset($product['id']) && is_string($product['id']) ? trim($product['id']) : '';
        $resolvedSku = isset($product['sku']) && is_string($product['sku']) ? trim($product['sku']) : $sku;
        $resolvedImage = $this->resolveProductImageUrlFromNode($product);
        if ($resolvedImage === null || $resolvedImage === '') {
            $resolvedImage = $imageUrl;
        }

        return [
            'id' => $id,
            'sku' => $resolvedSku,
            'image_url' => $resolvedImage,
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function resolveProductImageUrlFromNode(array $product): ?string
    {
        foreach (['large_thumbnail', 'thumbnail'] as $thumbKey) {
            $thumb = isset($product[$thumbKey]) && is_string($product[$thumbKey]) ? trim($product[$thumbKey]) : '';
            if ($thumb !== '') {
                return $thumb;
            }
        }
        $images = is_array($product['images'] ?? null) ? $product['images'] : [];
        $bestPos = PHP_INT_MAX;
        $imageUrl = null;
        foreach ($images as $img) {
            if (! is_array($img)) {
                continue;
            }
            $src = trim((string) ($img['src'] ?? ''));
            if ($src === '') {
                continue;
            }
            $pos = isset($img['position']) && is_numeric($img['position']) ? (int) $img['position'] : 999999;
            if ($imageUrl === null || $pos < $bestPos) {
                $imageUrl = $src;
                $bestPos = $pos;
            }
        }

        return $imageUrl;
    }

    /**
     * Create a product in ShipHero for a 3PL customer account (product_create).
     *
     * @return array{id: string, sku: string, name: string, image_url: string|null}
     *
     * @throws RuntimeException
     */
    public function createProduct(string $customerAccountId, string $sku, string $name, ?string $barcode = null): array
    {
        $customerAccountId = trim($customerAccountId);
        $sku = trim($sku);
        $name = trim($name);
        if ($customerAccountId === '' || $sku === '' || $name === '') {
            throw new RuntimeException('Customer account, SKU, and product name are required to create a product in ShipHero.');
        }

        $data = [
            'customer_account_id' => $customerAccountId,
            'sku' => $sku,
            'name' => $name,
            'price' => '0.00',
            'value' => '0.00',
        ];
        $barcode = $barcode !== null ? trim($barcode) : '';
        if ($barcode !== '') {
            $data['barcode'] = $barcode;
        }

        $warehouses = $this->listWarehouses();
        if ($warehouses !== []) {
            $data['warehouse_products'] = [
                [
                    'warehouse_id' => $warehouses[0]['id'],
                    'on_hand' => 0,
                ],
            ];
        }

        $graphql = <<<'GQL'
mutation ShipHeroProductCreate($data: CreateProductInput!) {
  product_create(data: $data) {
    request_id
    complexity
    product {
      id
      sku
      name
      barcode
      thumbnail
      large_thumbnail
    }
  }
}
GQL;

        $json = $this->client->query($graphql, ['data' => $data], true, [
            ShipHeroClient::OPTION_GRAPHQL_SUCCESS_FIELD => 'product_create',
        ]);
        $product = data_get($json, 'data.product_create.product');
        if (! is_array($product)) {
            $errs = $json['errors'] ?? [];
            $msg = is_array($errs) && isset($errs[0]['message'])
                ? (string) $errs[0]['message']
                : 'ShipHero did not return a product after create.';

            throw new RuntimeException($msg);
        }

        $id = isset($product['id']) && is_string($product['id']) ? trim($product['id']) : '';
        if ($id === '') {
            throw new RuntimeException('ShipHero created a product but returned no product id.');
        }

        $imageUrl = null;
        foreach (['large_thumbnail', 'thumbnail'] as $thumbKey) {
            $thumb = isset($product[$thumbKey]) && is_string($product[$thumbKey]) ? trim($product[$thumbKey]) : '';
            if ($thumb !== '') {
                $imageUrl = $thumb;
                break;
            }
        }

        return [
            'id' => $id,
            'sku' => isset($product['sku']) && is_string($product['sku']) ? trim($product['sku']) : $sku,
            'name' => isset($product['name']) && is_string($product['name']) ? trim($product['name']) : $name,
            'image_url' => $imageUrl,
        ];
    }

    /**
     * Upsert a single product row into the local inventory index after ShipHero product_create.
     *
     * @param  array{id?: string, sku?: string, name?: string, image_url?: string|null}  $created
     */
    public function upsertCreatedProductIndex(int $clientAccountId, string $customerAccountId, array $created): void
    {
        if ($clientAccountId <= 0) {
            return;
        }
        $sku = trim((string) ($created['sku'] ?? ''));
        if ($sku === '') {
            return;
        }
        $this->upsertInventoryIndexRows($clientAccountId, trim($customerAccountId), [
            [
                'sku' => $sku,
                'product_id' => trim((string) ($created['id'] ?? '')),
                'name' => trim((string) ($created['name'] ?? '')),
                'image_url' => $created['image_url'] ?? null,
                'on_hand' => 0,
                'allocated' => 0,
                'backorder' => 0,
                'product_active' => true,
            ],
        ]);
    }

    /**
     * Minimal product payload for portal inventory detail when SKU exists on an ASN line but not in ShipHero yet.
     *
     * @return array<string, mixed>
     */
    public function minimalProductFromAsnLine(
        string $sku,
        string $name,
        ?string $shipheroProductId = null,
        ?string $imageUrl = null
    ): array {
        $sku = trim($sku);
        $name = trim($name);

        return [
            'id' => $shipheroProductId !== null && trim($shipheroProductId) !== '' ? trim($shipheroProductId) : null,
            'sku' => $sku,
            'name' => $name !== '' ? $name : $sku,
            'barcode' => null,
            'image_url' => $imageUrl !== null && trim($imageUrl) !== '' ? trim($imageUrl) : null,
            'customs_value' => 0.0,
            'customs_description' => null,
            'dimensions' => [
                'weight' => null,
                'height' => null,
                'width' => null,
                'length' => null,
            ],
            'storage_cubic_feet' => null,
            'metrics' => [
                'on_hand' => 0,
                'allocated' => 0,
                'available' => 0,
                'backorder' => 0,
                'asn' => 0,
            ],
            'kit' => false,
            'kit_build' => false,
            'kit_components' => [],
            'parent_kits' => [],
            'warehouses' => [],
            'asn_line_only' => true,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $node
     * @return array{id: string, sku: string, name: string, barcode: string, image_url: string|null}|null
     */
    private function asnCatalogProductFromNode(?array $node): ?array
    {
        if (! $node) {
            return null;
        }
        $active = $node['active'] ?? null;
        if ($active === false) {
            return null;
        }
        if (($node['kit'] ?? false) === true || ($node['kit_build'] ?? false) === true) {
            return null;
        }
        $id = isset($node['id']) && (is_string($node['id']) || is_numeric($node['id']))
            ? trim((string) $node['id'])
            : '';
        $sku = isset($node['sku']) && is_string($node['sku']) ? trim($node['sku']) : '';
        if ($sku === '' && isset($node['sku']) && is_numeric($node['sku'])) {
            $sku = trim((string) $node['sku']);
        }
        if ($id === '' && $sku === '') {
            return null;
        }
        $imageUrl = null;
        $images = is_array($node['images'] ?? null) ? $node['images'] : [];
        $bestPos = PHP_INT_MAX;
        foreach ($images as $img) {
            if (! is_array($img)) {
                continue;
            }
            $src = trim((string) ($img['src'] ?? ''));
            if ($src === '') {
                continue;
            }
            $pos = isset($img['position']) && is_numeric($img['position']) ? (int) $img['position'] : 999999;
            if ($imageUrl === null || $pos < $bestPos) {
                $imageUrl = $src;
                $bestPos = $pos;
            }
        }

        return [
            'id' => $id !== '' ? $id : $sku,
            'sku' => $sku,
            'name' => isset($node['name']) && is_string($node['name']) ? $node['name'] : '',
            'barcode' => isset($node['barcode']) && is_string($node['barcode']) ? trim($node['barcode']) : '',
            'image_url' => $imageUrl,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @return list<array{id: string, sku: string, name: string, barcode: string, image_url: string|null}>
     */
    private function asnCatalogProductsFromEdges(array $edges): array
    {
        $out = [];
        foreach ($edges as $edge) {
            $node = is_array($edge['node'] ?? null) ? $edge['node'] : null;
            $p = $this->asnCatalogProductFromNode($node);
            if ($p !== null) {
                $out[] = $p;
            }
        }

        return $out;
    }

    private function asnCatalogProductFromIndexRow(ShipHeroInventoryProductIndex $row): array
    {
        return [
            'id' => (string) ($row->shiphero_product_id ?: $row->sku),
            'sku' => (string) $row->sku,
            'name' => (string) ($row->name ?? ''),
            'barcode' => $row->barcode ?? '',
            'image_url' => $row->image_url,
        ];
    }

    /**
     * @return array{products: list<array{id: string, sku: string, name: string, barcode: string, image_url: string|null}>, page_info: array{has_next_page: bool, end_cursor: string|null, next_search_skip?: int}}|null
     */
    private function searchAsnCatalogIndexProducts(
        ?int $clientAccountId,
        ?string $customerAccountId,
        int $first,
        ?string $after,
        ?string $searchQuery,
        int $searchSkip
    ): ?array {
        $query = ShipHeroInventoryProductIndex::query();
        $query = $this->applyInventoryIndexAccountScope($query, $clientAccountId, $customerAccountId);
        if ($query === null) {
            return null;
        }
        $query->where('product_active', true)
            ->where('kit', false)
            ->where('kit_build', false);

        $term = $this->normalizeInventoryIndexSearchValue($searchQuery ?? '');
        if ($term !== '') {
            if ($after !== null) {
                return null;
            }
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('sku_search', 'like', $like)
                    ->orWhere('name_search', 'like', $like)
                    ->orWhere('barcode_search', 'like', $like);
            });
            $query->orderByRaw(
                'CASE WHEN sku_search = ? THEN 0 WHEN barcode_search = ? THEN 1 WHEN sku_search LIKE ? THEN 2 WHEN name_search LIKE ? THEN 3 ELSE 4 END',
                [$term, $term, $term.'%', $term.'%']
            );
            $offset = max(0, $searchSkip);
        } else {
            $offset = 0;
            if ($after !== null && preg_match('/^idx:(\d+)$/', $after, $m)) {
                $offset = max(0, (int) $m[1]);
            } elseif ($after !== null) {
                return null;
            }
        }

        $rows = $query
            ->orderByDesc('on_hand')
            ->orderBy('sku')
            ->limit(1000)
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $seen = [];
        $products = [];
        foreach ($rows as $row) {
            $key = (string) ($row->shiphero_product_id ?: $row->sku);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $products[] = $this->asnCatalogProductFromIndexRow($row);
        }

        $first = max(1, min(200, $first));
        $slice = array_slice($products, $offset, $first);
        $next = $offset + count($slice);
        $hasMore = $next < count($products);

        return [
            'products' => $slice,
            'page_info' => [
                'has_next_page' => $hasMore,
                'end_cursor' => $term === '' ? ($hasMore ? 'idx:'.$next : null) : null,
                'next_search_skip' => $term !== '' ? $next : null,
            ],
        ];
    }

    /**
     * One GraphQL page of active, non-kit products for ASN line picker (skips empty filter pages server-side).
     *
     * @return array{products: list<array{id: string, sku: string, name: string, barcode: string, image_url: string|null}>, page_info: array{has_next_page: bool, end_cursor: string|null}}
     */
    public function listAsnProductCatalogPage(
        ?string $customerAccountId,
        int $graphqlFirst,
        ?string $after,
        ?string $searchQuery,
        int $searchSkip,
        ?int $clientAccountId = null,
        bool $refresh = false
    ): array {
        $graphqlFirst = max(25, min(100, $graphqlFirst));
        $searchQuery = is_string($searchQuery) ? trim($searchQuery) : '';
        $indexed = $refresh ? null : $this->searchAsnCatalogIndexProducts(
            $clientAccountId,
            $customerAccountId,
            $graphqlFirst,
            $after,
            $searchQuery !== '' ? $searchQuery : null,
            $searchSkip
        );
        if ($indexed !== null) {
            return $indexed;
        }

        if ($searchQuery !== '') {
            $fast = $this->listAsnProductCatalogTryDirectProductSearch(
                $customerAccountId,
                $graphqlFirst,
                $after,
                $searchQuery,
                max(0, $searchSkip),
                $clientAccountId
            );
            if ($fast !== null) {
                return $fast;
            }

            return $this->listAsnProductCatalogSearchPage(
                $customerAccountId,
                $graphqlFirst,
                $after,
                $searchQuery,
                max(0, $searchSkip),
            );
        }

        return $this->listAsnProductCatalogBrowsePage($customerAccountId, $graphqlFirst, $after, $clientAccountId);
    }

    /**
     * Same fast path as inventory list: single product(sku|barcode) when the term is one token.
     *
     * @return array{products: list<array{id: string, sku: string, name: string, barcode: string, image_url: string|null}>, page_info: array{has_next_page: bool, end_cursor: string|null, next_search_skip: int}}|null
     */
    private function listAsnProductCatalogTryDirectProductSearch(
        ?string $customerAccountId,
        int $desired,
        ?string $after,
        string $searchQuery,
        int $searchSkip,
        ?int $clientAccountId
    ): ?array {
        if ($after !== null || ! $this->inventoryListSearchEligibleForDirectProductLookup($searchQuery)) {
            return null;
        }
        $term = trim($searchQuery);

        $data = null;
        $relaxedSubstring = false;
        try {
            $data = $this->fetchProductBySku($term, $customerAccountId);
        } catch (\Throwable $e) {
            $data = null;
        }
        if ($data === null) {
            try {
                $data = $this->fetchProductByBarcode($term, $customerAccountId);
                $relaxedSubstring = true;
            } catch (\Throwable $e) {
                $data = null;
            }
        }
        if ($data === null || $data === []) {
            return null;
        }
        $this->upsertInventoryIndexRows(
            $clientAccountId,
            $customerAccountId,
            $this->expandInventoryProductEdgesToRows([['node' => $data]], 'no', 'active')
        );

        $products = $this->asnCatalogProductsFromEdges([['node' => $data]]);
        if ($products === []) {
            return null;
        }

        if (! $relaxedSubstring) {
            $qLower = mb_strtolower($term);
            $filtered = [];
            foreach ($products as $p) {
                $skuL = mb_strtolower((string) ($p['sku'] ?? ''));
                $nameL = mb_strtolower((string) ($p['name'] ?? ''));
                if (mb_strpos($skuL, $qLower) === false && mb_strpos($nameL, $qLower) === false) {
                    continue;
                }
                $filtered[] = $p;
            }
            if ($filtered === []) {
                return null;
            }
            $products = $filtered;
        }

        $desired = max(1, min(200, $desired));
        $searchSkip = max(0, $searchSkip);
        $total = count($products);
        $slice = array_slice($products, $searchSkip, $desired);
        $delivered = count($slice);
        $nextSearchSkip = $searchSkip + $delivered;

        return [
            'products' => $slice,
            'page_info' => [
                'has_next_page' => $nextSearchSkip < $total,
                'end_cursor' => null,
                'next_search_skip' => $nextSearchSkip,
            ],
        ];
    }

    /**
     * Cursor-based browse without client-side SKU/name filtering.
     *
     * @return array{products: list<array{id: string, sku: string, name: string, barcode: string, image_url: string|null}>, page_info: array{has_next_page: bool, end_cursor: string|null}}
     */
    private function listAsnProductCatalogBrowsePage(
        ?string $customerAccountId,
        int $graphqlFirst,
        ?string $after,
        ?int $clientAccountId
    ): array
    {
        $graphql = <<<'GQL'
query ShipHeroAsnProductCatalogBrowse($customer_account_id: String, $first: Int!, $after: String) {
  products(customer_account_id: $customer_account_id) {
    data(first: $first, after: $after) {
      pageInfo {
        hasNextPage
        endCursor
      }
      edges {
        node {
          id
          sku
          name
          active
          kit
          kit_build
          barcode
          images {
            src
            position
          }
        }
      }
    }
  }
}
GQL;

        $graphqlAfter = is_string($after) && trim($after) !== '' ? trim($after) : null;

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $vars = array_merge(
                ['first' => $graphqlFirst, 'after' => $graphqlAfter],
                $this->customerAccountVariables($customerAccountId)
            );
            $json = $this->client->query($graphql, $vars);
            $edges = data_get($json, 'data.products.data.edges');
            $pageInfo = data_get($json, 'data.products.data.pageInfo');
            $hasNext = is_array($pageInfo) && (($pageInfo['hasNextPage'] ?? false) === true);
            $endCursor = is_array($pageInfo) && isset($pageInfo['endCursor']) && is_string($pageInfo['endCursor'])
                ? $pageInfo['endCursor']
                : null;
            if ($endCursor === '') {
                $endCursor = null;
            }

            $products = is_array($edges) ? $this->asnCatalogProductsFromEdges($edges) : [];
            if (count($products) > 0) {
                $this->upsertInventoryIndexRows(
                    $clientAccountId,
                    $customerAccountId,
                    $this->expandInventoryProductEdgesToRows($edges, 'no', 'active')
                );

                return [
                    'products' => $products,
                    'page_info' => [
                        'has_next_page' => $hasNext && $endCursor !== null,
                        'end_cursor' => $hasNext ? $endCursor : null,
                    ],
                ];
            }
            if (! $hasNext || $endCursor === null) {
                return [
                    'products' => [],
                    'page_info' => [
                        'has_next_page' => false,
                        'end_cursor' => null,
                    ],
                ];
            }

            $graphqlAfter = $endCursor;
        }

        return [
            'products' => [],
            'page_info' => [
                'has_next_page' => false,
                'end_cursor' => null,
            ],
        ];
    }

    /**
     * @return array{products: list<array{id: string, sku: string, name: string, barcode: string, image_url: string|null}>, page_info: array{has_next_page: bool, end_cursor: string|null, next_search_skip: int}}
     */
    private function listAsnProductCatalogSearchPage(
        ?string $customerAccountId,
        int $desired,
        ?string $after,
        string $searchQuery,
        int $searchSkip
    ): array {
        $graphql = <<<'GQL'
query ShipHeroAsnProductCatalogSearch($customer_account_id: String, $first: Int!, $after: String) {
  products(customer_account_id: $customer_account_id) {
    data(first: $first, after: $after) {
      pageInfo {
        hasNextPage
        endCursor
      }
      edges {
        node {
          id
          sku
          name
          active
          kit
          kit_build
          barcode
          images {
            src
            position
          }
        }
      }
    }
  }
}
GQL;

        $qLower = mb_strtolower($searchQuery);
        $matchSkip = $after === null ? $searchSkip : 0;
        $output = [];
        $graphqlAfter = is_string($after) && trim($after) !== '' ? trim($after) : null;
        $resumeCursor = null;
        $lastFetchHadNext = false;
        $iterations = 0;
        $innerFirst = min(100, max(40, $desired * 3));

        while ($iterations < 1) {
            $iterations++;
            $vars = array_merge(
                ['first' => $innerFirst, 'after' => $graphqlAfter],
                $this->customerAccountVariables($customerAccountId)
            );
            $json = $this->client->query($graphql, $vars);
            $edges = data_get($json, 'data.products.data.edges');
            $pageInfo = data_get($json, 'data.products.data.pageInfo');
            $lastFetchHadNext = is_array($pageInfo) && (($pageInfo['hasNextPage'] ?? false) === true);
            $endCursor = is_array($pageInfo) && isset($pageInfo['endCursor']) && is_string($pageInfo['endCursor'])
                ? $pageInfo['endCursor']
                : null;
            if ($endCursor === '') {
                $endCursor = null;
            }
            if (! is_array($edges)) {
                break;
            }
            foreach ($this->asnCatalogProductsFromEdges($edges) as $p) {
                $skuL = mb_strtolower((string) ($p['sku'] ?? ''));
                $nameL = mb_strtolower((string) ($p['name'] ?? ''));
                if (mb_strpos($skuL, $qLower) === false && mb_strpos($nameL, $qLower) === false) {
                    continue;
                }
                if ($matchSkip > 0) {
                    $matchSkip--;

                    continue;
                }
                $output[] = $p;
            }
            $graphqlAfter = $endCursor;
            $resumeCursor = $endCursor;
            if (! $lastFetchHadNext || $graphqlAfter === null) {
                break;
            }
        }

        $delivered = count($output);
        $nextSearchSkip = $searchSkip + $delivered;
        $hasMore = $lastFetchHadNext && $resumeCursor !== null;

        return [
            'products' => $output,
            'page_info' => [
                'has_next_page' => $hasMore,
                'end_cursor' => $resumeCursor,
                'next_search_skip' => $nextSearchSkip,
            ],
        ];
    }

    /**
     * Active, non-kit ShipHero products for portal ASN line picker (cursor pagination, capped).
     *
     * @return array{products: list<array{id: string, sku: string, name: string, barcode: string}>, truncated: bool}
     */
    public function listAsnProductCatalog(?string $customerAccountId, int $first = 100, int $maxPages = 50): array
    {
        $first = max(1, min(100, $first));
        $graphql = <<<'GQL'
query ShipHeroAsnProductCatalog($customer_account_id: String, $first: Int!, $after: String) {
  products(customer_account_id: $customer_account_id) {
    data(first: $first, after: $after) {
      pageInfo {
        hasNextPage
        endCursor
      }
      edges {
        node {
          id
          sku
          name
          active
          kit
          kit_build
          barcode
          images {
            src
            position
          }
        }
      }
    }
  }
}
GQL;

        $out = [];
        $after = null;
        $truncated = false;
        for ($page = 0; $page < $maxPages; $page++) {
            $vars = array_merge(
                ['first' => $first, 'after' => $after],
                $this->customerAccountVariables($customerAccountId)
            );
            $json = $this->client->query($graphql, $vars);
            $edges = data_get($json, 'data.products.data.edges');
            if (! is_array($edges)) {
                break;
            }
            foreach ($this->asnCatalogProductsFromEdges($edges) as $p) {
                $out[] = $p;
            }
            $pageInfo = data_get($json, 'data.products.data.pageInfo');
            $hasNext = is_array($pageInfo) && (($pageInfo['hasNextPage'] ?? false) === true);
            $endCursor = is_array($pageInfo) && isset($pageInfo['endCursor']) && is_string($pageInfo['endCursor'])
                ? $pageInfo['endCursor']
                : null;
            if (! $hasNext || $endCursor === null || $endCursor === '') {
                break;
            }
            if ($page + 1 >= $maxPages) {
                $truncated = $hasNext;
                break;
            }
            $after = $endCursor;
        }

        return ['products' => $out, 'truncated' => $truncated];
    }

    /**
     * @param callable(): (array<string,mixed>|null) $fetcher
     * @return array<string,mixed>|null
     */
    private function safeProductFetch(string $logEvent, string $term, ?string $customerAccountId, callable $fetcher): ?array
    {
        try {
            $data = $fetcher();
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::warning($logEvent, [
                'term' => $term,
                'customer_account_id' => $customerAccountId,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProductBySkuBasic(string $sku, ?string $customerAccountId): ?array
    {
        if (! is_string($customerAccountId) || trim($customerAccountId) === '') {
            $graphqlNoCustomer = <<<'GQL'
query ShipHeroProductBySkuBasic($sku: String!) {
  product(sku: $sku) {
    data {
      id
      sku
      name
      barcode
      customs_value
      customs_description
      dimensions {
        weight
        height
        width
        length
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        reserve_inventory
        inventory_bin
        inventory_overstock_bin
        locations(first: 100) {
          edges {
            node {
              id
              location_id
              quantity
              location {
                name
              }
            }
          }
        }
      }
    }
  }
}
GQL;
            $json = $this->client->query($graphqlNoCustomer, ['sku' => $sku]);
            $data = data_get($json, 'data.product.data');
            return is_array($data) ? $data : null;
        }
        $graphql = <<<'GQL'
query ShipHeroProductBySkuBasic($sku: String!, $customer_account_id: String) {
  product(sku: $sku, customer_account_id: $customer_account_id) {
    data {
      id
      sku
      name
      barcode
      customs_value
      customs_description
      dimensions {
        weight
        height
        width
        length
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        reserve_inventory
        inventory_bin
        inventory_overstock_bin
        locations(first: 100) {
          edges {
            node {
              id
              location_id
              quantity
              location {
                name
              }
            }
          }
        }
      }
    }
  }
}
GQL;
        $vars = array_merge(['sku' => $sku], $this->customerAccountVariables($customerAccountId));
        $json = $this->client->query($graphql, $vars);
        $data = data_get($json, 'data.product.data');
        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProductByBarcodeBasic(string $barcode, ?string $customerAccountId): ?array
    {
        if (! is_string($customerAccountId) || trim($customerAccountId) === '') {
            $graphqlNoCustomer = <<<'GQL'
query ShipHeroProductByBarcodeBasic($barcode: String!) {
  product(barcode: $barcode) {
    data {
      id
      sku
      name
      barcode
      customs_value
      customs_description
      dimensions {
        weight
        height
        width
        length
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        reserve_inventory
        inventory_bin
        inventory_overstock_bin
        locations(first: 100) {
          edges {
            node {
              id
              location_id
              quantity
              location {
                name
              }
            }
          }
        }
      }
    }
  }
}
GQL;
            $json = $this->client->query($graphqlNoCustomer, ['barcode' => $barcode]);
            $data = data_get($json, 'data.product.data');
            return is_array($data) ? $data : null;
        }
        $graphql = <<<'GQL'
query ShipHeroProductByBarcodeBasic($barcode: String!, $customer_account_id: String) {
  product(barcode: $barcode, customer_account_id: $customer_account_id) {
    data {
      id
      sku
      name
      barcode
      customs_value
      customs_description
      dimensions {
        weight
        height
        width
        length
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        reserve_inventory
        inventory_bin
        inventory_overstock_bin
        locations(first: 100) {
          edges {
            node {
              id
              location_id
              quantity
              location {
                name
              }
            }
          }
        }
      }
    }
  }
}
GQL;
        $vars = array_merge(['barcode' => $barcode], $this->customerAccountVariables($customerAccountId));
        $json = $this->client->query($graphql, $vars);
        $data = data_get($json, 'data.product.data');
        return is_array($data) ? $data : null;
    }

    /**
     * Replace on-hand quantity for one SKU at one location (absolute quantity).
     *
     * @return array<string, mixed> Normalized single-warehouse slice (same shape as search warehouses entries)
     */
    /**
     * @param  string|null  $customerAccountId  ShipHero GraphQL `customer_account_id` (3PL), or null
     */
    public function replaceLocationQuantity(
        string $sku,
        string $warehouseId,
        string $locationId,
        int $quantity,
        string $reason,
        ?string $customerAccountId = null
    ): array {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('SKU is required.');
        }
        $warehouseId = trim($warehouseId);
        $locationId = trim($locationId);
        if ($warehouseId === '' || $locationId === '') {
            throw new RuntimeException('warehouse_id and location_id are required.');
        }
        if ($quantity < 0) {
            throw new RuntimeException('quantity must be zero or greater.');
        }

        $input = [
            'sku' => $sku,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'quantity' => $quantity,
            'reason' => $reason !== '' ? $reason : 'CRM inventory replace',
            'includes_non_sellable' => false,
        ];
        if (is_string($customerAccountId) && trim($customerAccountId) !== '') {
            $input['customer_account_id'] = trim($customerAccountId);
        }

        $graphql = <<<'GQL'
mutation ShipHeroInventoryReplace($data: ReplaceInventoryInput!) {
  inventory_replace(data: $data) {
    request_id
    complexity
    warehouse_product {
      warehouse_id
      warehouse {
        identifier
        company_name
      }
      locations(first: 100) {
        edges {
          node {
            id
            location_id
            quantity
            location {
              name
            }
          }
        }
      }
    }
  }
}
GQL;

        $json = $this->client->query($graphql, ['data' => $input]);
        $wp = data_get($json, 'data.inventory_replace.warehouse_product');
        if (! is_array($wp)) {
            throw new RuntimeException('ShipHero did not return warehouse_product after replace.');
        }

        $wid = isset($wp['warehouse_id']) && is_string($wp['warehouse_id']) ? $wp['warehouse_id'] : $warehouseId;
        $whName = $this->warehouseDisplayName(is_array($wp['warehouse'] ?? null) ? $wp['warehouse'] : []);

        return [
            'warehouse_id' => $wid,
            'warehouse_name' => $whName,
            'locations' => $this->normalizeLocations($wp['locations'] ?? null, $wid),
        ];
    }

    /**
     * Add quantity at a location (creates SKU location assignment when missing).
     *
     * @return array<string, mixed> Normalized single-warehouse slice
     */
    public function addLocationQuantity(
        string $sku,
        string $warehouseId,
        string $locationId,
        int $quantity,
        string $reason,
        ?string $customerAccountId = null
    ): array {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('SKU is required.');
        }
        $warehouseId = trim($warehouseId);
        $locationId = trim($locationId);
        if ($warehouseId === '' || $locationId === '') {
            throw new RuntimeException('warehouse_id and location_id are required.');
        }
        if ($quantity <= 0) {
            throw new RuntimeException('quantity must be greater than zero.');
        }

        $input = [
            'sku' => $sku,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'quantity' => $quantity,
            'reason' => $reason !== '' ? $reason : 'CRM inventory add',
        ];
        if (is_string($customerAccountId) && trim($customerAccountId) !== '') {
            $input['customer_account_id'] = trim($customerAccountId);
        }

        $graphql = <<<'GQL'
mutation ShipHeroInventoryAdd($data: AddInventoryInput!) {
  inventory_add(data: $data) {
    request_id
    complexity
    warehouse_product {
      warehouse_id
      warehouse {
        identifier
        company_name
      }
      locations(first: 100) {
        edges {
          node {
            id
            location_id
            quantity
            location {
              name
            }
          }
        }
      }
    }
  }
}
GQL;

        $json = $this->client->query($graphql, ['data' => $input]);
        $wp = data_get($json, 'data.inventory_add.warehouse_product');
        if (! is_array($wp)) {
            throw new RuntimeException('ShipHero did not return warehouse_product after add.');
        }

        $wid = isset($wp['warehouse_id']) && is_string($wp['warehouse_id']) ? $wp['warehouse_id'] : $warehouseId;
        $whName = $this->warehouseDisplayName(is_array($wp['warehouse'] ?? null) ? $wp['warehouse'] : []);

        return [
            'warehouse_id' => $wid,
            'warehouse_name' => $whName,
            'locations' => $this->normalizeLocations($wp['locations'] ?? null, $wid),
        ];
    }

    /**
     * Resolve a warehouse location by name, creating it when missing.
     *
     * @return array{id: string, name: string, type: ?string, pickable: ?bool, sellable: ?bool}
     */
    public function ensureWarehouseLocation(string $warehouseId, string $name, ?string $customerAccountId = null): array
    {
        $warehouseId = trim($warehouseId);
        $name = trim($name);
        if ($warehouseId === '' || $name === '') {
            throw new RuntimeException('warehouse_id and location name are required.');
        }

        $existing = $this->resolveWarehouseLocation($warehouseId, $name, $customerAccountId);
        if (is_array($existing) && trim((string) ($existing['id'] ?? '')) !== '') {
            return $existing;
        }

        $input = [
            'warehouse_id' => $warehouseId,
            'name' => $name,
            'pickable' => false,
            'sellable' => true,
        ];

        $graphql = <<<'GQL'
mutation ShipHeroLocationCreate($data: CreateLocationInput!) {
  location_create(data: $data) {
    request_id
    location {
      id
      name
      pickable
      sellable
    }
  }
}
GQL;

        $json = $this->client->query($graphql, ['data' => $input]);
        $node = data_get($json, 'data.location_create.location');
        if (! is_array($node)) {
            throw new RuntimeException('ShipHero did not return location after create.');
        }
        $id = trim((string) ($node['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('ShipHero location_create response is missing id.');
        }

        return [
            'id' => $id,
            'name' => trim((string) ($node['name'] ?? $name)),
            'type' => null,
            'pickable' => array_key_exists('pickable', $node) ? (bool) $node['pickable'] : false,
            'sellable' => array_key_exists('sellable', $node) ? (bool) $node['sellable'] : true,
        ];
    }

    /**
     * @param string|null $customerAccountId
     * @return list<array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}>
     */
    public function listLocations(string $warehouseId, ?string $customerAccountId = null): array
    {
        $warehouseId = trim($warehouseId);
        if ($warehouseId === '') {
            return [];
        }
        $queries = [
            [
                'graphql' => <<<'GQL'
query ShipHeroLocationsByWarehouseNoCustomer($warehouse_id: String!) {
  locations(warehouse_id: $warehouse_id) {
    data {
      edges {
        node {
          id
          name
          zone
          type {
            name
          }
          pickable
          sellable
        }
      }
    }
  }
}
GQL,
                'vars' => ['warehouse_id' => $warehouseId],
            ],
            [
                'graphql' => <<<'GQL'
query ShipHeroLocationsByWarehouseScalarType($warehouse_id: String!) {
  locations(warehouse_id: $warehouse_id) {
    data {
      edges {
        node {
          id
          name
          zone
          type
          pickable
          sellable
        }
      }
    }
  }
}
GQL,
                'vars' => ['warehouse_id' => $warehouseId],
            ],
            [
                'graphql' => <<<'GQL'
query ShipHeroLocationsByWarehouse($warehouse_id: String!, $customer_account_id: String) {
  locations(warehouse_id: $warehouse_id, customer_account_id: $customer_account_id) {
    data {
      edges {
        node {
          id
          name
          zone
          type {
            name
          }
          pickable
          sellable
        }
      }
    }
  }
}
GQL,
                'vars' => array_merge(['warehouse_id' => $warehouseId], $this->customerAccountVariables($customerAccountId)),
            ],
        ];
        $merged = [];
        $lastError = null;
        foreach ($queries as $candidate) {
            try {
                $json = $this->client->query($candidate['graphql'], $candidate['vars']);
                $edges = data_get($json, 'data.locations.data.edges');
                if (! is_array($edges) || $edges === []) {
                    continue;
                }
                $out = [];
                foreach ($edges as $edge) {
                    if (! is_array($edge)) {
                        continue;
                    }
                    $parsed = $this->parseLocationNode($edge['node'] ?? null);
                    if ($parsed === null) {
                        continue;
                    }
                    $out[] = $parsed;
                }
                if ($out !== []) {
                    return $out;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }
        if ($lastError instanceof \Throwable) {
            throw new RuntimeException($lastError->getMessage());
        }

        return [];
    }

    /**
     * @param string|null $customerAccountId
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}|null
     */
    public function resolveWarehouseLocation(string $warehouseId, string $locationInput, ?string $customerAccountId = null): ?array
    {
        $needle = $this->normalizeLocationSearchTerm($locationInput);
        if ($needle === '') {
            return null;
        }
        $warehouseId = trim($warehouseId);
        if ($warehouseId === '') {
            return null;
        }

        $byName = $this->lookupWarehouseLocationByName($warehouseId, $needle, $customerAccountId);
        if (is_array($byName)) {
            return $byName;
        }

        $byItemLocation = $this->lookupWarehouseLocationByItemLocationName($warehouseId, $needle, $customerAccountId);
        if (is_array($byItemLocation)) {
            return $byItemLocation;
        }

        $bySingularName = $this->lookupLocationBySingularName($needle, $warehouseId);
        if (is_array($bySingularName)) {
            return $bySingularName;
        }

        if ($this->looksLikeShipHeroLocationId($needle)) {
            foreach ($this->candidateLocationIdsFromInput($needle) as $candidateId) {
                $byId = $this->lookupWarehouseLocationById($candidateId);
                if (is_array($byId)) {
                    return $byId;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $node
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}|null
     */
    private function parseLocationNode($node): ?array
    {
        if (! is_array($node)) {
            return null;
        }
        $id = trim((string) ($node['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        $name = trim((string) ($node['name'] ?? ''));
        $typeName = trim((string) data_get($node, 'type.name', ''));
        if ($typeName === '') {
            $typeName = trim((string) ($node['type'] ?? ''));
        }
        $pickableRaw = array_key_exists('pickable', $node)
            ? $node['pickable']
            : (array_key_exists('is_pickable', $node) ? $node['is_pickable'] : null);
        $pickable = null;
        if (is_bool($pickableRaw)) {
            $pickable = $pickableRaw;
        } elseif (is_int($pickableRaw) || is_float($pickableRaw)) {
            $pickable = ((int) $pickableRaw) === 1;
        } elseif (is_string($pickableRaw)) {
            $normalizedPickable = strtolower(trim($pickableRaw));
            if (in_array($normalizedPickable, ['1', 'true', 'yes'], true)) {
                $pickable = true;
            } elseif (in_array($normalizedPickable, ['0', 'false', 'no'], true)) {
                $pickable = false;
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'type' => $typeName !== '' ? $typeName : null,
            'pickable' => $pickable,
            'sellable' => array_key_exists('sellable', $node) ? (bool) $node['sellable'] : null,
        ];
    }

    /**
     * @param  list<array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}>  $locations
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}|null
     */
    private function findLocationInCatalog(array $locations, string $needle): ?array
    {
        foreach ($locations as $loc) {
            if (strcasecmp((string) ($loc['id'] ?? ''), $needle) === 0) {
                return $loc;
            }
        }
        foreach ($locations as $loc) {
            if (strcasecmp((string) ($loc['name'] ?? ''), $needle) === 0) {
                return $loc;
            }
        }

        return null;
    }

    /**
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}|null
     */
    private function lookupWarehouseLocationByName(string $warehouseId, string $name, ?string $customerAccountId = null): ?array
    {
        $warehouseId = trim($warehouseId);
        $name = trim($name);
        if ($warehouseId === '' || $name === '') {
            return null;
        }
        $variableSets = [
            ['warehouse_id' => $warehouseId, 'name' => $name],
        ];
        $customer = is_string($customerAccountId) ? trim($customerAccountId) : '';
        if ($customer !== '') {
            $variableSets[] = [
                'warehouse_id' => $warehouseId,
                'name' => $name,
                'customer_account_id' => $customer,
            ];
        }
        $queries = [
            [
                'graphql' => <<<'GQL'
query ShipHeroLocationByWarehouseName($warehouse_id: String!, $name: String!) {
  locations(warehouse_id: $warehouse_id, name: $name) {
    data {
      edges {
        node {
          id
          name
          zone
          type {
            name
          }
          pickable
          sellable
        }
      }
    }
  }
}
GQL,
                'with_customer' => false,
            ],
            [
                'graphql' => <<<'GQL'
query ShipHeroLocationByWarehouseNameCustomer($warehouse_id: String!, $name: String!, $customer_account_id: String!) {
  locations(warehouse_id: $warehouse_id, name: $name, customer_account_id: $customer_account_id) {
    data {
      edges {
        node {
          id
          name
          zone
          type {
            name
          }
          pickable
          sellable
        }
      }
    }
  }
}
GQL,
                'with_customer' => true,
            ],
            [
                'graphql' => <<<'GQL'
query ShipHeroLocationByWarehouseNameScalarType($warehouse_id: String!, $name: String!) {
  locations(warehouse_id: $warehouse_id, name: $name) {
    data {
      edges {
        node {
          id
          name
          zone
          type
          pickable
          sellable
        }
      }
    }
  }
}
GQL,
                'with_customer' => false,
            ],
        ];
        foreach ($variableSets as $vars) {
            foreach ($queries as $candidate) {
                if (($candidate['with_customer'] ?? false) && ! isset($vars['customer_account_id'])) {
                    continue;
                }
                if (! ($candidate['with_customer'] ?? false) && isset($vars['customer_account_id'])) {
                    continue;
                }
                try {
                    $json = $this->client->query($candidate['graphql'], $vars);
                    $match = $this->firstLocationMatchFromEdges(
                        data_get($json, 'data.locations.data.edges'),
                        $name
                    );
                    if (is_array($match)) {
                        return $match;
                    }
                } catch (\Throwable $e) {
                    // Try next query variant.
                }
            }
        }

        return null;
    }

    /**
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}|null
     */
    private function lookupWarehouseLocationByItemLocationName(
        string $warehouseId,
        string $name,
        ?string $customerAccountId = null
    ): ?array {
        $warehouseId = trim($warehouseId);
        $name = trim($name);
        if ($warehouseId === '' || $name === '') {
            return null;
        }
        $variableSets = [
            ['warehouse_id' => $warehouseId, 'location_name' => $name],
        ];
        $customer = is_string($customerAccountId) ? trim($customerAccountId) : '';
        if ($customer !== '') {
            $variableSets[] = [
                'warehouse_id' => $warehouseId,
                'location_name' => $name,
                'customer_account_id' => $customer,
            ];
        }
        $queries = [
            [
                'graphql' => <<<'GQL'
query ShipHeroItemLocationByName($warehouse_id: String!, $location_name: String!) {
  item_locations(warehouse_id: $warehouse_id, location_name: $location_name) {
    data(first: 5) {
      edges {
        node {
          location {
            id
            name
            pickable
            sellable
            type {
              name
            }
          }
        }
      }
    }
  }
}
GQL,
                'with_customer' => false,
            ],
            [
                'graphql' => <<<'GQL'
query ShipHeroItemLocationByNameCustomer($warehouse_id: String!, $location_name: String!, $customer_account_id: String!) {
  item_locations(warehouse_id: $warehouse_id, location_name: $location_name, customer_account_id: $customer_account_id) {
    data(first: 5) {
      edges {
        node {
          location {
            id
            name
            pickable
            sellable
            type {
              name
            }
          }
        }
      }
    }
  }
}
GQL,
                'with_customer' => true,
            ],
        ];
        foreach ($variableSets as $vars) {
            foreach ($queries as $candidate) {
                if (($candidate['with_customer'] ?? false) && ! isset($vars['customer_account_id'])) {
                    continue;
                }
                if (! ($candidate['with_customer'] ?? false) && isset($vars['customer_account_id'])) {
                    continue;
                }
                try {
                    $json = $this->client->query($candidate['graphql'], $vars);
                    $edges = data_get($json, 'data.item_locations.data.edges');
                    if (! is_array($edges)) {
                        continue;
                    }
                    foreach ($edges as $edge) {
                        if (! is_array($edge)) {
                            continue;
                        }
                        $parsed = $this->parseLocationNode(data_get($edge, 'node.location'));
                        if ($parsed === null) {
                            continue;
                        }
                        if (strcasecmp($parsed['name'], $name) === 0) {
                            return $parsed;
                        }
                    }
                } catch (\Throwable $e) {
                    // Try next query variant.
                }
            }
        }

        return null;
    }

    /**
     * @param  mixed  $edges
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}|null
     */
    private function firstLocationMatchFromEdges($edges, string $name): ?array
    {
        if (! is_array($edges)) {
            return null;
        }
        $firstMatch = null;
        foreach ($edges as $edge) {
            $parsed = $this->parseLocationNode(is_array($edge) ? ($edge['node'] ?? null) : null);
            if ($parsed === null) {
                continue;
            }
            if ($firstMatch === null) {
                $firstMatch = $parsed;
            }
            if (strcasecmp($parsed['name'], $name) === 0) {
                return $parsed;
            }
        }

        return $firstMatch;
    }

    /**
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}|null
     */
    private function lookupLocationBySingularName(string $name, string $warehouseId): ?array
    {
        $name = trim($name);
        $warehouseId = trim($warehouseId);
        if ($name === '') {
            return null;
        }
        $queries = [
            <<<'GQL'
query ShipHeroLocationBySingularName($name: String!) {
  location(name: $name) {
    data {
      id
      name
      warehouse_id
      pickable
      sellable
      type {
        name
      }
    }
  }
}
GQL,
            <<<'GQL'
query ShipHeroLocationBySingularNameScalar($name: String!) {
  location(name: $name) {
    data {
      id
      name
      warehouse_id
      pickable
      sellable
      type
    }
  }
}
GQL,
        ];
        foreach ($queries as $graphql) {
            try {
                $json = $this->client->query($graphql, ['name' => $name]);
                $node = data_get($json, 'data.location.data');
                if (! is_array($node)) {
                    continue;
                }
                if ($warehouseId !== '') {
                    $nodeWarehouseId = trim((string) ($node['warehouse_id'] ?? ''));
                    if ($nodeWarehouseId !== '' && strcasecmp($nodeWarehouseId, $warehouseId) !== 0) {
                        continue;
                    }
                }
                $parsed = $this->parseLocationNode($node);
                if ($parsed !== null) {
                    return $parsed;
                }
            } catch (\Throwable $e) {
                // Try next query variant.
            }
        }

        return null;
    }

    /**
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}|null
     */
    private function lookupWarehouseLocationById(string $locationId): ?array
    {
        $locationId = trim($locationId);
        if ($locationId === '') {
            return null;
        }
        $queries = [
            <<<'GQL'
query ShipHeroLocationRecordById($id: String!) {
  location(id: $id) {
    data {
      id
      name
      pickable
      sellable
      type {
        name
      }
    }
  }
}
GQL,
            <<<'GQL'
query ShipHeroLocationRecordByIdScalar($id: String!) {
  location(id: $id) {
    data {
      id
      name
      pickable
      sellable
      type
    }
  }
}
GQL,
        ];
        foreach ($queries as $graphql) {
            try {
                $json = $this->client->query($graphql, ['id' => $locationId]);
                $parsed = $this->parseLocationNode(data_get($json, 'data.location.data'));
                if ($parsed !== null) {
                    return $parsed;
                }
            } catch (\Throwable $e) {
                // Try next query variant.
            }
        }

        return null;
    }

    private function normalizeLocationSearchTerm(string $locationInput): string
    {
        $needle = trim($locationInput);
        if ($needle === '') {
            return '';
        }
        $needle = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $needle);
        $collapsed = preg_replace('/\s+/u', ' ', $needle);

        return is_string($collapsed) ? trim($collapsed) : $needle;
    }

    private function looksLikeShipHeroLocationId(string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return false;
        }
        if (ctype_digit($needle)) {
            return true;
        }
        if (! preg_match('/^[A-Za-z0-9+\/]+=*$/', $needle)) {
            return false;
        }
        $decoded = base64_decode($needle, true);
        if (! is_string($decoded) || $decoded === '') {
            return false;
        }

        return strpos($decoded, 'Bin:') === 0
            || strpos($decoded, 'Warehouse:') === 0
            || strpos($decoded, 'Location:') === 0;
    }

    /**
     * @return list<string>
     */
    private function candidateLocationIdsFromInput(string $needle): array
    {
        $needle = trim($needle);
        if ($needle === '') {
            return [];
        }
        if (ctype_digit($needle)) {
            return [base64_encode('Bin:'.$needle)];
        }
        if ($this->looksLikeShipHeroLocationId($needle)) {
            return [$needle];
        }

        return [];
    }

    /**
     * Resolve a location from the product's warehouse snapshot (by id or display name).
     *
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}|null
     */
    public function resolveProductWarehouseLocation(
        string $sku,
        string $warehouseId,
        string $locationInput,
        ?string $customerAccountId = null
    ): ?array {
        $needle = trim($locationInput);
        if ($needle === '') {
            return null;
        }
        $product = $this->searchProduct($sku, $warehouseId, $customerAccountId);
        if (! is_array($product)) {
            return null;
        }
        $warehouse = null;
        foreach (($product['warehouses'] ?? []) as $wh) {
            if (is_array($wh) && (string) ($wh['warehouse_id'] ?? '') === $warehouseId) {
                $warehouse = $wh;
                break;
            }
        }
        if (! is_array($warehouse)) {
            return null;
        }
        foreach (($warehouse['locations'] ?? []) as $loc) {
            if (! is_array($loc)) {
                continue;
            }
            $locId = trim((string) ($loc['location_id'] ?? ''));
            $locName = trim((string) ($loc['location_name'] ?? ''));
            if ($locId !== '' && strcasecmp($locId, $needle) === 0) {
                return [
                    'id' => $locId,
                    'name' => $locName !== '' ? $locName : $locId,
                    'type' => isset($loc['type']) && is_string($loc['type']) ? $loc['type'] : null,
                    'pickable' => array_key_exists('pickable', $loc) ? $loc['pickable'] : null,
                    'sellable' => null,
                ];
            }
        }
        foreach (($warehouse['locations'] ?? []) as $loc) {
            if (! is_array($loc)) {
                continue;
            }
            $locId = trim((string) ($loc['location_id'] ?? ''));
            $locName = trim((string) ($loc['location_name'] ?? ''));
            if ($locName !== '' && strcasecmp($locName, $needle) === 0) {
                return [
                    'id' => $locId !== '' ? $locId : $locName,
                    'name' => $locName,
                    'type' => isset($loc['type']) && is_string($loc['type']) ? $loc['type'] : null,
                    'pickable' => array_key_exists('pickable', $loc) ? $loc['pickable'] : null,
                    'sellable' => null,
                ];
            }
        }

        return null;
    }

    /**
     * @param string|null $customerAccountId
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}
     */
    public function updateLocationPickable(
        string $locationId,
        bool $pickable,
        ?bool $sellable = null,
        ?string $customerAccountId = null
    ): array {
        $locationId = trim($locationId);
        if ($locationId === '') {
            throw new RuntimeException('location_id is required.');
        }
        $input = [
            'location_id' => $locationId,
            'pickable' => $pickable,
        ];
        if ($sellable !== null) {
            $input['sellable'] = $sellable;
        }
        if (is_string($customerAccountId) && trim($customerAccountId) !== '') {
            $input['customer_account_id'] = trim($customerAccountId);
        }
        $attemptInputs = [];
        // Try a few payload shapes because accounts can differ on accepted fields.
        $attemptInputs[] = ['location_id' => $locationId, 'pickable' => $pickable];
        if ($sellable !== null) {
            $attemptInputs[] = ['location_id' => $locationId, 'pickable' => $pickable, 'sellable' => $sellable];
        }
        if (is_string($customerAccountId) && trim($customerAccountId) !== '') {
            $withCustomer = ['location_id' => $locationId, 'pickable' => $pickable, 'customer_account_id' => trim($customerAccountId)];
            $attemptInputs[] = $withCustomer;
            if ($sellable !== null) {
                $withCustomer['sellable'] = $sellable;
                $attemptInputs[] = $withCustomer;
            }
        }
        $json = null;
        $lastError = null;
        foreach ($attemptInputs as $candidateInput) {
            try {
                $json = $this->client->query($this->buildLocationUpdateMutationLiteral($candidateInput));
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }
        if (! is_array($json)) {
            throw new RuntimeException($lastError instanceof \Throwable ? $lastError->getMessage() : 'Could not update location.');
        }
        $node = data_get($json, 'data.location_update.location');
        if (! is_array($node)) {
            throw new RuntimeException('ShipHero did not return updated location.');
        }
        $id = trim((string) ($node['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('ShipHero location_update response is missing id.');
        }
        $name = trim((string) ($node['name'] ?? ''));
        $typeName = null;
        try {
            $typeLookup = <<<'GQL'
query ShipHeroLocationById($id: String!) {
  location(id: $id) {
    data {
      type {
        name
      }
    }
  }
}
GQL;
            $typeJson = $this->client->query($typeLookup, ['id' => $id]);
            $typeRaw = trim((string) data_get($typeJson, 'data.location.data.type.name', ''));
            if ($typeRaw !== '') {
                $typeName = $typeRaw;
            }
        } catch (\Throwable $e) {
            // Some accounts expose location.type as scalar.
            try {
                $typeLookupScalar = <<<'GQL'
query ShipHeroLocationByIdScalar($id: String!) {
  location(id: $id) {
    data {
      type
    }
  }
}
GQL;
                $typeJson = $this->client->query($typeLookupScalar, ['id' => $id]);
                $typeRaw = trim((string) data_get($typeJson, 'data.location.data.type', ''));
                if ($typeRaw !== '') {
                    $typeName = $typeRaw;
                }
            } catch (\Throwable $ignored) {
                $typeName = null;
            }
        }
        return [
            'id' => $id,
            'name' => $name,
            'type' => $typeName !== null && trim($typeName) !== '' ? trim($typeName) : null,
            'pickable' => array_key_exists('pickable', $node) ? (bool) $node['pickable'] : null,
            'sellable' => array_key_exists('sellable', $node) ? (bool) $node['sellable'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $input
     */
    private function buildLocationUpdateMutationLiteral(array $input): string
    {
        $parts = [];
        foreach ($input as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }
            $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
            if (! is_string($safeKey) || $safeKey === '') {
                continue;
            }
            if (is_bool($value)) {
                $parts[] = $safeKey.': '.($value ? 'true' : 'false');
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $parts[] = $safeKey.': '.$value;
                continue;
            }
            $stringValue = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
            $parts[] = $safeKey.': "'.$stringValue.'"';
        }
        $dataLiteral = implode("\n    ", $parts);
        return 'mutation ShipHeroLocationUpdate {'."\n"
            .'  location_update(data: {'."\n"
            .'    '.$dataLiteral."\n"
            .'  }) {'."\n"
            .'    location {'."\n"
            .'      id'."\n"
            .'      name'."\n"
            .'      pickable'."\n"
            .'      sellable'."\n"
            .'    }'."\n"
            .'  }'."\n"
            .'}';
    }

    /**
     * @return array<string, mixed>|null  product.data
     */
    private function fetchProductBySku(string $sku, ?string $customerAccountId): ?array
    {
        if (! is_string($customerAccountId) || trim($customerAccountId) === '') {
            $graphqlNoCustomer = <<<'GQL'
query ShipHeroProductBySku($sku: String!) {
  product(sku: $sku) {
    data {
      id
      legacy_id
      account_id
      sku
      name
      barcode
      active
      kit
      kit_build
      customs_value
      customs_description
      images {
        src
        position
      }
      dimensions {
        weight
        height
        width
        length
      }
      kit_components {
        quantity
        sku
      }
      components {
        sku
        name
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        backorder
        active
        inventory_bin
        inventory_overstock_bin
        reserve_inventory
        non_sellable_quantity
        in_tote
        reorder_level
        reorder_amount
        replenishment_level
        replenishment_max_level
        replenishment_increment
        warehouse {
          identifier
          company_name
        }
        locations(first: 100) {
          edges {
            node {
              id
              location_id
              quantity
              location {
                name
              }
            }
          }
        }
      }
    }
  }
}
GQL;
            $json = $this->client->query($graphqlNoCustomer, ['sku' => $sku]);
            $data = data_get($json, 'data.product.data');
            return is_array($data) ? $data : null;
        }
        $graphql = <<<'GQL'
query ShipHeroProductBySku($sku: String!, $customer_account_id: String) {
  product(sku: $sku, customer_account_id: $customer_account_id) {
    data {
      id
      legacy_id
      account_id
      sku
      name
      barcode
      active
      kit
      kit_build
      customs_value
      customs_description
      images {
        src
        position
      }
      dimensions {
        weight
        height
        width
        length
      }
      kit_components {
        quantity
        sku
      }
      components {
        sku
        name
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        backorder
        active
        inventory_bin
        inventory_overstock_bin
        reserve_inventory
        non_sellable_quantity
        in_tote
        reorder_level
        reorder_amount
        replenishment_level
        replenishment_max_level
        replenishment_increment
        warehouse {
          identifier
          company_name
        }
        locations(first: 100) {
          edges {
            node {
              id
              location_id
              quantity
              location {
                name
              }
            }
          }
        }
      }
    }
  }
}
GQL;
        $vars = array_merge(['sku' => $sku], $this->customerAccountVariables($customerAccountId));
        $json = $this->client->query($graphql, $vars);
        $data = data_get($json, 'data.product.data');

        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>|null  product.data
     */
    private function fetchProductByBarcode(string $barcode, ?string $customerAccountId): ?array
    {
        if (! is_string($customerAccountId) || trim($customerAccountId) === '') {
            $graphqlNoCustomer = <<<'GQL'
query ShipHeroProductByBarcode($barcode: String!) {
  product(barcode: $barcode) {
    data {
      id
      legacy_id
      account_id
      sku
      name
      barcode
      active
      kit
      kit_build
      customs_value
      customs_description
      images {
        src
        position
      }
      dimensions {
        weight
        height
        width
        length
      }
      kit_components {
        quantity
        sku
      }
      components {
        sku
        name
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        backorder
        active
        inventory_bin
        inventory_overstock_bin
        reserve_inventory
        non_sellable_quantity
        in_tote
        reorder_level
        reorder_amount
        replenishment_level
        replenishment_max_level
        replenishment_increment
        warehouse {
          identifier
          company_name
        }
        locations(first: 100) {
          edges {
            node {
              id
              location_id
              quantity
              location {
                name
              }
            }
          }
        }
      }
    }
  }
}
GQL;
            $json = $this->client->query($graphqlNoCustomer, ['barcode' => $barcode]);
            $data = data_get($json, 'data.product.data');
            return is_array($data) ? $data : null;
        }
        $graphql = <<<'GQL'
query ShipHeroProductByBarcode($barcode: String!, $customer_account_id: String) {
  product(barcode: $barcode, customer_account_id: $customer_account_id) {
    data {
      id
      legacy_id
      account_id
      sku
      name
      barcode
      active
      kit
      kit_build
      customs_value
      customs_description
      images {
        src
        position
      }
      dimensions {
        weight
        height
        width
        length
      }
      kit_components {
        quantity
        sku
      }
      components {
        sku
        name
      }
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        backorder
        active
        inventory_bin
        inventory_overstock_bin
        reserve_inventory
        non_sellable_quantity
        in_tote
        reorder_level
        reorder_amount
        replenishment_level
        replenishment_max_level
        replenishment_increment
        warehouse {
          identifier
          company_name
        }
        locations(first: 100) {
          edges {
            node {
              id
              location_id
              quantity
              location {
                name
              }
            }
          }
        }
      }
    }
  }
}
GQL;
        $vars = array_merge(['barcode' => $barcode], $this->customerAccountVariables($customerAccountId));
        $json = $this->client->query($graphql, $vars);
        $data = data_get($json, 'data.product.data');

        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>|null  product.data
     */
    private function fetchProductById(string $id, ?string $customerAccountId = null): ?array
    {
        $graphql = <<<'GQL'
query ShipHeroProductById($id: String!, $customer_account_id: String) {
  product(id: $id, customer_account_id: $customer_account_id) {
    data {
      id
      legacy_id
      account_id
      name
      sku
      price
      value
      barcode
      country_of_manufacture
      dimensions {
        weight
        height
        width
        length
      }
      tariff_code
      value_currency
      kit
      kit_build
      no_air
      final_sale
      customs_value
      customs_description
      not_owned
      dropship
      created_at
      warehouse_products {
        warehouse_id
        warehouse_identifier
        on_hand
        allocated
        inventory_bin
        inventory_overstock_bin
        reserve_inventory
        non_sellable_quantity
        in_tote
        reorder_level
        reorder_amount
        replenishment_level
        replenishment_max_level
        replenishment_increment
        warehouse {
          identifier
          company_name
        }
        locations(first: 100) {
          edges {
            node {
              id
              location_id
              quantity
              location {
                name
              }
            }
          }
        }
      }
      images {
        src
        position
      }
      tags
      vendors {
        vendor_id
        vendor_sku
      }
      product_note
      virtual
      ignore_on_invoice
      ignore_on_customs
      active
      kit_components {
        quantity
        sku
      }
      components {
        sku
        name
      }
    }
  }
}
GQL;
        $json = $this->client->query($graphql, array_merge(
            ['id' => $id],
            $this->customerAccountVariables($customerAccountId)
        ));
        $data = data_get($json, 'data.product.data');
        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeProduct(array $data, ?string $warehouseFilter): array
    {
        $warehousesOut = [];
        $onHand = 0;
        $allocated = 0;
        $backorder = 0;
        $wps = $data['warehouse_products'] ?? null;
        if (! is_array($wps)) {
            $wps = [];
        }

        foreach ($wps as $wp) {
            if (! is_array($wp)) {
                continue;
            }
            $wid = isset($wp['warehouse_id']) && is_string($wp['warehouse_id']) ? $wp['warehouse_id'] : '';
            if ($wid === '') {
                continue;
            }
            if (is_string($warehouseFilter) && $warehouseFilter !== '' && $wid !== $warehouseFilter) {
                continue;
            }
            $wh = is_array($wp['warehouse'] ?? null) ? $wp['warehouse'] : [];
            $warehouseOnHand = $this->toIntNumber($wp['on_hand'] ?? 0);
            $warehouseAllocated = $this->toIntNumber(
                $wp['allocated'] ?? ($wp['reserve_inventory'] ?? 0)
            );
            $warehouseBackorder = $this->toIntNumber($wp['backorder'] ?? 0);
            $onHand += max(0, $warehouseOnHand);
            $allocated += max(0, $warehouseAllocated);
            $backorder += max(0, $warehouseBackorder);
            $normalizedLocations = $this->normalizeLocations($wp['locations'] ?? null, $wid);
            if ($normalizedLocations === []) {
                $normalizedLocations = $this->fallbackLocationsFromWarehouseProduct($wp, $wid);
            }
            $warehousesOut[] = [
                'warehouse_id' => $wid,
                'warehouse_name' => $this->warehouseDisplayNameWithFallback($wh, $wp),
                'on_hand' => max(0, $warehouseOnHand),
                'allocated' => max(0, $warehouseAllocated),
                'backorder' => max(0, $warehouseBackorder),
                'locations' => $normalizedLocations,
            ];
        }

        $dimensions = is_array($data['dimensions'] ?? null) ? $data['dimensions'] : [];
        $imageUrl = null;
        $images = is_array($data['images'] ?? null) ? $data['images'] : [];
        $bestPos = PHP_INT_MAX;
        foreach ($images as $img) {
            if (! is_array($img)) {
                continue;
            }
            $src = trim((string) ($img['src'] ?? ''));
            if ($src === '') {
                continue;
            }
            $pos = isset($img['position']) && is_numeric($img['position']) ? (int) $img['position'] : 999999;
            if ($imageUrl === null || $pos < $bestPos) {
                $imageUrl = $src;
                $bestPos = $pos;
            }
        }
        $customsCandidates = [
            $data['customs_value'] ?? null,
            data_get($data, 'customs.value'),
            data_get($data, 'customs.customs_value'),
            data_get($data, 'customs.amount'),
            $data['customsValue'] ?? null,
            $data['custom_value'] ?? null,
            $data['value'] ?? null,
        ];
        $customsValue = null;
        foreach ($customsCandidates as $candidate) {
            $normalizedCandidate = $this->normalizeNumericDisplay($candidate);
            $candidateNumeric = is_numeric($normalizedCandidate) ? (float) $normalizedCandidate : null;
            if ($candidateNumeric !== null && $candidateNumeric > 0) {
                $customsValue = $normalizedCandidate;
                break;
            }
            if ($customsValue === null) {
                $customsValue = $normalizedCandidate;
            }
        }
        if ($customsValue === null) {
            $customsValue = 0.0;
        }
        $customsDescription = isset($data['customs_description']) && is_string($data['customs_description'])
            ? trim($data['customs_description'])
            : '';
        if ($customsDescription === '') {
            $fallbackDescription = isset($data['product_note']) && is_string($data['product_note'])
                ? trim($data['product_note'])
                : '';
            $customsDescription = $fallbackDescription;
        }

        $legacyRaw = $data['legacy_id'] ?? null;
        $shipheroLegacyId = is_int($legacyRaw)
            ? $legacyRaw
            : (is_numeric($legacyRaw) ? (int) $legacyRaw : null);

        return [
            'id' => isset($data['id']) && is_string($data['id']) ? $data['id'] : null,
            'shiphero_legacy_id' => $shipheroLegacyId,
            'sku' => isset($data['sku']) && is_string($data['sku']) ? $data['sku'] : '',
            'name' => isset($data['name']) && is_string($data['name']) ? $data['name'] : null,
            'barcode' => isset($data['barcode']) && is_string($data['barcode']) ? $data['barcode'] : null,
            'image_url' => $imageUrl,
            'customs_value' => $customsValue,
            'customs_description' => $customsDescription !== '' ? $customsDescription : null,
            'dimensions' => [
                'weight' => $this->normalizeNumericDisplay($dimensions['weight'] ?? null),
                'height' => $this->normalizeNumericDisplay($dimensions['height'] ?? null),
                'width' => $this->normalizeNumericDisplay($dimensions['width'] ?? null),
                'length' => $this->normalizeNumericDisplay($dimensions['length'] ?? null),
            ],
            'storage_cubic_feet' => $this->storageCubicFeetFromShipHeroDimensions($dimensions),
            'metrics' => [
                'on_hand' => $onHand,
                'allocated' => $allocated,
                'available' => max(0, $onHand - $allocated),
                'backorder' => $backorder,
                'asn' => 0,
            ],
            'kit' => $this->normalizeShipHeroBool($data['kit'] ?? null),
            'kit_build' => $this->normalizeShipHeroBool($data['kit_build'] ?? null),
            'kit_components' => $this->resolveKitComponentsFromProductData($data),
            'parent_kits' => $this->normalizeParentKits($data['parent_kits'] ?? null),
            'warehouses' => $warehousesOut,
        ];
    }

    /**
     * @param  array<string, mixed>  $warehouse
     */
    private function warehouseDisplayName(array $warehouse): string
    {
        $id = isset($warehouse['identifier']) && is_string($warehouse['identifier']) ? $warehouse['identifier'] : null;
        $co = isset($warehouse['company_name']) && is_string($warehouse['company_name']) ? $warehouse['company_name'] : null;

        if ($id !== null && $co !== null && $id !== $co) {
            return $id.' — '.$co;
        }

        return $id ?? $co ?? 'Warehouse';
    }

    /**
     * @param array<string,mixed> $warehouse
     * @param array<string,mixed> $warehouseProduct
     */
    private function warehouseDisplayNameWithFallback(array $warehouse, array $warehouseProduct): string
    {
        $fromWarehouse = $this->warehouseDisplayName($warehouse);
        if ($fromWarehouse !== 'Warehouse') {
            return $fromWarehouse;
        }
        $identifier = trim((string) ($warehouseProduct['warehouse_identifier'] ?? ''));
        if ($identifier !== '') {
            return $identifier;
        }

        return 'Warehouse';
    }

    /**
     * @param  mixed  $locations
     *
     * @return array<int, array{
     *  item_location_id:string,
     *  location_id:string,
     *  location_name:string|null,
     *  quantity:int,
     *  pickable:bool|null,
     *  type:string|null,
     *  warehouse_id:string|null
     * }>
     */
    private function normalizeLocations($locations, ?string $warehouseId = null): array
    {
        $edges = null;
        if (is_array($locations)) {
            if (isset($locations['edges']) && is_array($locations['edges'])) {
                $edges = $locations['edges'];
            } elseif (isset($locations['data']['edges']) && is_array($locations['data']['edges'])) {
                $edges = $locations['data']['edges'];
            }
        }
        if (! is_array($edges)) {
            return [];
        }

        $out = [];
        foreach ($edges as $index => $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $node = $edge['node'] ?? null;
            if (! is_array($node)) {
                continue;
            }
            $itemLocId = isset($node['id']) && is_string($node['id']) ? $node['id'] : '';
            $locId = isset($node['location_id']) && is_string($node['location_id']) ? $node['location_id'] : '';
            if ($locId === '') {
                continue;
            }
            if ($itemLocId === '') {
                $itemLocId = 'item:'.$locId.':'.$index;
            }
            $qty = $node['quantity'] ?? 0;
            $qty = is_int($qty) ? $qty : (int) $qty;
            $loc = is_array($node['location'] ?? null) ? $node['location'] : [];
            $locName = isset($loc['name']) && is_string($loc['name']) ? $loc['name'] : null;
            $pickable = null;
            $pickableRaw = array_key_exists('pickable', $loc)
                ? $loc['pickable']
                : (array_key_exists('pickable', $node) ? $node['pickable'] : null);
            if (is_bool($pickableRaw)) {
                $pickable = $pickableRaw;
            } elseif (is_int($pickableRaw) || is_float($pickableRaw)) {
                $pickable = ((int) $pickableRaw) === 1;
            } elseif (is_string($pickableRaw)) {
                $normalizedPickable = strtolower(trim($pickableRaw));
                if (in_array($normalizedPickable, ['1', 'true', 'yes'], true)) {
                    $pickable = true;
                } elseif (in_array($normalizedPickable, ['0', 'false', 'no'], true)) {
                    $pickable = false;
                }
            }
            $type = trim((string) data_get($loc, 'type.name', ''));
            if ($type === '') {
                $type = trim((string) ($loc['type'] ?? ''));
            }
            if ($type === '') {
                $type = trim((string) data_get($node, 'type.name', ''));
            }
            if ($type === '') {
                $type = trim((string) ($node['type'] ?? ''));
            }
            if ($type === '') {
                $type = $this->extractLocationTypeLabel($locName);
            }

            $out[] = [
                'item_location_id' => $itemLocId,
                'location_id' => $locId,
                'location_name' => $locName,
                'quantity' => max(0, $qty),
                'pickable' => $pickable,
                'type' => $type,
                'warehouse_id' => $warehouseId,
            ];
        }

        return $out;
    }

    /**
     * Build fallback location rows from `inventory_bin` / `inventory_overstock_bin`
     * when ShipHero does not return `locations.edges`.
     *
     * @param array<string,mixed> $warehouseProduct
     * @return list<array{
     *  item_location_id:string,
     *  location_id:string,
     *  location_name:string|null,
     *  quantity:int,
     *  pickable:bool|null,
     *  type:string|null,
     *  warehouse_id:string|null
     * }>
     */
    private function fallbackLocationsFromWarehouseProduct(array $warehouseProduct, ?string $warehouseId = null): array
    {
        $out = [];
        $bin = trim((string) ($warehouseProduct['inventory_bin'] ?? ''));
        $overstock = trim((string) ($warehouseProduct['inventory_overstock_bin'] ?? ''));
        $onHand = max(0, (int) ($warehouseProduct['on_hand'] ?? 0));

        if ($bin !== '') {
            $out[] = [
                'item_location_id' => 'fallback:bin:'.$bin,
                'location_id' => $bin,
                'location_name' => $bin,
                'quantity' => $onHand,
                'pickable' => true,
                'type' => $this->extractLocationTypeLabel($bin),
                'warehouse_id' => $warehouseId,
            ];
        }
        if ($overstock !== '' && $overstock !== $bin) {
            $out[] = [
                'item_location_id' => 'fallback:overstock:'.$overstock,
                'location_id' => $overstock,
                'location_name' => $overstock,
                'quantity' => 0,
                'pickable' => false,
                'type' => $this->extractLocationTypeLabel($overstock),
                'warehouse_id' => $warehouseId,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{sku:string,quantity:float}>
     */
    private function resolveKitComponentsFromProductData(array $data): array
    {
        $fromKitComponents = $this->normalizeKitComponents($data['kit_components'] ?? null);
        if ($fromKitComponents !== []) {
            return $fromKitComponents;
        }

        return $this->normalizeKitComponentsFromProductNodes($data['components'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $full
     * @param  array<string, mixed>  $merged
     * @return array<string, mixed>
     */
    private function mergeProductKitFields(array $base, array $full, array $merged): array
    {
        $merged['kit'] = $this->normalizeShipHeroBool($full['kit'] ?? null)
            || $this->normalizeShipHeroBool($base['kit'] ?? null);
        $merged['kit_build'] = $this->normalizeShipHeroBool($full['kit_build'] ?? null)
            || $this->normalizeShipHeroBool($base['kit_build'] ?? null);

        $components = $this->resolveKitComponentsFromProductData($full);
        if ($components === []) {
            $components = $this->resolveKitComponentsFromProductData($base);
        }
        $merged['kit_components'] = $components;

        return $merged;
    }

    private function normalizeShipHeroBool($value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }
        if ($value === false || $value === 0 || $value === null) {
            return false;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * @param mixed $components
     * @return list<array{sku:string,quantity:float}>
     */
    private function normalizeKitComponents($components): array
    {
        if (! is_array($components)) {
            return [];
        }

        if (isset($components['edges']) && is_array($components['edges'])) {
            return $this->normalizeKitComponents($components['edges']);
        }
        if (isset($components['data']['edges']) && is_array($components['data']['edges'])) {
            return $this->normalizeKitComponents($components['data']['edges']);
        }

        $out = [];
        foreach ($components as $key => $component) {
            if ($key === 'edges' || $key === 'data') {
                continue;
            }
            if (is_array($component) && isset($component['node']) && is_array($component['node'])) {
                $component = $component['node'];
            }
            if (! is_array($component)) {
                continue;
            }
            $sku = trim((string) ($component['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $out[] = [
                'sku' => $sku,
                'quantity' => (float) ($component['quantity'] ?? 1),
            ];
        }

        return $out;
    }

    /**
     * ShipHero {@see Product::components} — kit member products when kit_components is empty.
     *
     * @param mixed $nodes
     * @return list<array{sku:string,quantity:float}>
     */
    private function normalizeKitComponentsFromProductNodes($nodes): array
    {
        if (! is_array($nodes)) {
            return [];
        }
        if (isset($nodes['edges']) && is_array($nodes['edges'])) {
            $nodes = $nodes['edges'];
        }

        $out = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (isset($node['node']) && is_array($node['node'])) {
                $node = $node['node'];
            }
            $sku = trim((string) ($node['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $out[] = [
                'sku' => $sku,
                'quantity' => (float) ($node['quantity'] ?? 1),
            ];
        }

        return $out;
    }

    /**
     * Kits (parent products) that include this SKU as a component.
     *
     * @return list<array{sku:string,name:?string,quantity:float,kit:bool,kit_build:bool}>
     */
    public function findParentKitsForComponentSku(string $customerAccountId, string $componentSku, int $maxResults = 40): array
    {
        $customer = trim($customerAccountId);
        $componentSkuNorm = mb_strtolower(trim($componentSku));
        if ($customer === '' || $componentSkuNorm === '') {
            return [];
        }

        $graphql = <<<'GQL'
query ShipHeroKitProductsForParentLookup($customer_account_id: String, $first: Int!, $after: String) {
  products(customer_account_id: $customer_account_id) {
    data(first: $first, after: $after) {
      pageInfo {
        hasNextPage
        endCursor
      }
      edges {
        node {
          sku
          name
          kit
          kit_build
          kit_components {
            sku
            quantity
          }
          components {
            sku
            name
          }
        }
      }
    }
  }
}
GQL;

        $matches = [];
        $after = null;
        $perPage = 50;
        $maxPages = 12;

        for ($page = 0; $page < $maxPages && count($matches) < $maxResults; $page++) {
            $json = $this->client->query($graphql, array_merge(
                ['first' => $perPage, 'after' => $after],
                $this->customerAccountVariables($customer)
            ));
            $edges = data_get($json, 'data.products.data.edges', []);
            if (! is_array($edges)) {
                break;
            }

            foreach ($edges as $edge) {
                if (count($matches) >= $maxResults) {
                    break 2;
                }
                $node = is_array($edge['node'] ?? null) ? $edge['node'] : null;
                if ($node === null) {
                    continue;
                }
                if (! $this->normalizeShipHeroBool($node['kit'] ?? null) && ! $this->normalizeShipHeroBool($node['kit_build'] ?? null)) {
                    continue;
                }
                $kitSku = trim((string) ($node['sku'] ?? ''));
                if ($kitSku === '' || mb_strtolower($kitSku) === $componentSkuNorm) {
                    continue;
                }
                foreach ($this->resolveKitComponentsFromProductData($node) as $component) {
                    if (mb_strtolower($component['sku']) !== $componentSkuNorm) {
                        continue;
                    }
                    $matches[] = [
                        'sku' => $kitSku,
                        'name' => isset($node['name']) && is_string($node['name']) ? trim($node['name']) : null,
                        'quantity' => (float) ($component['quantity'] ?? 1),
                        'kit' => $this->normalizeShipHeroBool($node['kit'] ?? null),
                        'kit_build' => $this->normalizeShipHeroBool($node['kit_build'] ?? null),
                    ];
                    break;
                }
            }

            $hasNext = (bool) data_get($json, 'data.products.data.pageInfo.hasNextPage', false);
            $after = data_get($json, 'data.products.data.pageInfo.endCursor');
            if (! $hasNext || ! is_string($after) || $after === '') {
                break;
            }
        }

        return $matches;
    }

    /**
     * @param mixed $rows
     * @return list<array{sku:string,name:?string,quantity:float,kit:bool,kit_build:bool}>
     */
    private function normalizeParentKits($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $out[] = [
                'sku' => $sku,
                'name' => isset($row['name']) && is_string($row['name']) ? trim($row['name']) : null,
                'quantity' => (float) ($row['quantity'] ?? 1),
                'kit' => $this->normalizeShipHeroBool($row['kit'] ?? null),
                'kit_build' => $this->normalizeShipHeroBool($row['kit_build'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private function toIntNumber($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        if (is_string($value)) {
            $clean = preg_replace('/[^0-9.\-]/', '', $value);
            if (is_string($clean) && $clean !== '' && is_numeric($clean)) {
                return (int) round((float) $clean);
            }
        }
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }
        return 0;
    }

    /**
     * @param mixed $value
     * @return float|string|null
     */
    /**
     * Storage cubic feet from ShipHero product dimensions (inches): H × W × L / 1728.
     *
     * @param  array<string, mixed>  $dimensions
     */
    private function storageCubicFeetFromShipHeroDimensions(array $dimensions): ?float
    {
        $height = $this->normalizeNumericDisplay($dimensions['height'] ?? null);
        $width = $this->normalizeNumericDisplay($dimensions['width'] ?? null);
        $length = $this->normalizeNumericDisplay($dimensions['length'] ?? null);
        if (! is_numeric($height) || ! is_numeric($width) || ! is_numeric($length)) {
            return null;
        }
        $h = (float) $height;
        $w = (float) $width;
        $l = (float) $length;
        if ($h <= 0 || $w <= 0 || $l <= 0) {
            return null;
        }
        $cubic = ($h * $w * $l) / 1728;

        return round($cubic, 3);
    }

    /**
     * @param mixed $value
     * @return float|string|null
     */
    private function normalizeNumericDisplay($value)
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $raw = trim($value);
            if ($raw === '') {
                return null;
            }
            $clean = preg_replace('/[^0-9.\-]/', '', $raw);
            if (is_string($clean) && $clean !== '' && is_numeric($clean)) {
                return (float) $clean;
            }
            return $raw;
        }
        return null;
    }

    private function extractLocationTypeLabel(?string $locationName): ?string
    {
        $source = trim((string) $locationName);
        if ($source === '') {
            return null;
        }
        if (preg_match('/\\b(bin|pallet|shelf)\\s*\\(\\s*(small|medium|large|x-?large)\\s*\\)/i', $source, $m) === 1) {
            $base = ucfirst(strtolower((string) $m[1]));
            $size = strtolower((string) $m[2]);
            $normalizedSize = $size === 'xlarge' || $size === 'x-large' ? 'X-Large' : ucfirst($size);
            return $base.' ('.$normalizedSize.')';
        }
        if (preg_match('/\\bcustom\\b/i', $source) === 1) return 'Custom';
        if (preg_match('/\\bsleeve\\b/i', $source) === 1) return 'Sleeve';
        return null;
    }

    /**
     * @param string|null $customerAccountId
     * @return array<string,mixed>|null
     */
    public function getProductDetailBySku(
        string $sku,
        ?string $warehouseId = null,
        ?string $customerAccountId = null,
        bool $includeKits = false
    ): ?array
    {
        $base = null;
        try {
            $base = $this->fetchProductBySku(trim($sku), $customerAccountId);
        } catch (\Throwable $e) {
            Log::warning('shiphero.inventory.detail.by_sku_failed', [
                'sku' => $sku,
                'customer_account_id' => $customerAccountId,
                'message' => $e->getMessage(),
            ]);
        }
        if ($base === null) {
            try {
                $base = $this->fetchProductByBarcode(trim($sku), $customerAccountId);
            } catch (\Throwable $e) {
                Log::warning('shiphero.inventory.detail.by_barcode_failed', [
                    'sku_or_barcode' => $sku,
                    'customer_account_id' => $customerAccountId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        if ($base === null) {
            return null;
        }
        $id = isset($base['id']) && is_string($base['id']) ? trim($base['id']) : '';
        if ($id === '') {
            return $this->normalizeProduct($base, $warehouseId);
        }
        try {
            $full = $this->fetchProductById($id, $customerAccountId);
            if (is_array($full)) {
                $merged = array_merge($base, $full);
                // ShipHero can return 0 for customs_value on product(id) while SKU/barcode
                // responses still contain the real customs value. Preserve the non-zero value.
                $merged['customs_value'] = $this->pickPreferredPositiveNumeric(
                    $full['customs_value'] ?? null,
                    $base['customs_value'] ?? null
                );
                $merged['customsValue'] = $this->pickPreferredPositiveNumeric(
                    $full['customsValue'] ?? null,
                    $base['customsValue'] ?? null
                );
                $merged['custom_value'] = $this->pickPreferredPositiveNumeric(
                    $full['custom_value'] ?? null,
                    $base['custom_value'] ?? null
                );
                $merged['warehouse_products'] = $this->pickWarehouseProductsPayload(
                    $base['warehouse_products'] ?? null,
                    $full['warehouse_products'] ?? null
                );
                $merged = $this->mergeProductKitFields($base, $full, $merged);
                $normalized = $this->normalizeProduct($merged, $warehouseId);
                if (! $includeKits) {
                    $normalized['parent_kits'] = [];
                    $normalized['kit_components'] = [];
                } elseif ($normalized['parent_kits'] === [] && $customerAccountId !== null && trim($customerAccountId) !== '') {
                    $normalized['parent_kits'] = $this->findParentKitsForComponentSku(
                        $customerAccountId,
                        (string) ($normalized['sku'] ?? $sku)
                    );
                }
                Log::info('shiphero.inventory.detail.normalized', [
                    'sku' => $normalized['sku'] ?? null,
                    'customer_account_id' => $customerAccountId,
                    'customs_value' => $normalized['customs_value'] ?? null,
                    'customs_description' => $normalized['customs_description'] ?? null,
                    'metrics' => $normalized['metrics'] ?? null,
                    'source' => 'product_by_id',
                    'raw_customs' => [
                        'base_customs_value' => $base['customs_value'] ?? null,
                        'full_customs_value' => $full['customs_value'] ?? null,
                        'base_customsValue' => $base['customsValue'] ?? null,
                        'full_customsValue' => $full['customsValue'] ?? null,
                        'base_custom_value' => $base['custom_value'] ?? null,
                        'full_custom_value' => $full['custom_value'] ?? null,
                    ],
                ]);
                return $this->enrichProductLocationsMeta($normalized, $customerAccountId);
            }
        } catch (\Throwable $e) {
            Log::warning('shiphero.inventory.detail.by_id_failed_fallback', [
                'sku' => $sku,
                'product_id' => $id,
                'customer_account_id' => $customerAccountId,
                'message' => $e->getMessage(),
            ]);
        }

        $normalized = $this->normalizeProduct($base, $warehouseId);
        if (! $includeKits) {
            $normalized['parent_kits'] = [];
            $normalized['kit_components'] = [];
        } elseif ($normalized['parent_kits'] === [] && $customerAccountId !== null && trim($customerAccountId) !== '') {
            $normalized['parent_kits'] = $this->findParentKitsForComponentSku(
                $customerAccountId,
                (string) ($normalized['sku'] ?? $sku)
            );
        }
        Log::info('shiphero.inventory.detail.normalized', [
            'sku' => $normalized['sku'] ?? null,
            'customer_account_id' => $customerAccountId,
            'customs_value' => $normalized['customs_value'] ?? null,
            'customs_description' => $normalized['customs_description'] ?? null,
            'metrics' => $normalized['metrics'] ?? null,
            'source' => 'product_by_sku_or_barcode',
        ]);
        return $this->enrichProductLocationsMeta($normalized, $customerAccountId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getParentKitsForSku(string $sku, ?string $customerAccountId = null): array
    {
        if ($customerAccountId === null || trim($customerAccountId) === '') {
            return [];
        }

        return $this->findParentKitsForComponentSku(
            $customerAccountId,
            trim($sku)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getKitComponentsForSku(string $sku, ?string $customerAccountId = null): array
    {
        $base = null;
        try {
            $base = $this->fetchProductBySku(trim($sku), $customerAccountId);
        } catch (\Throwable $e) {
            Log::warning('shiphero.inventory.kit_components.by_sku_failed', [
                'sku' => $sku,
                'customer_account_id' => $customerAccountId,
                'message' => $e->getMessage(),
            ]);
        }
        if ($base === null) {
            try {
                $base = $this->fetchProductByBarcode(trim($sku), $customerAccountId);
            } catch (\Throwable $e) {
                Log::warning('shiphero.inventory.kit_components.by_barcode_failed', [
                    'sku_or_barcode' => $sku,
                    'customer_account_id' => $customerAccountId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        if ($base === null) {
            return [];
        }
        $merged = $base;
        $id = isset($base['id']) && is_string($base['id']) ? trim($base['id']) : '';
        if ($id !== '') {
            try {
                $full = $this->fetchProductById($id, $customerAccountId);
                if (is_array($full)) {
                    $merged = $this->mergeProductKitFields($base, $full, array_merge($base, $full));
                }
            } catch (\Throwable $e) {
                Log::warning('shiphero.inventory.kit_components.by_id_failed', [
                    'sku' => $sku,
                    'product_id' => $id,
                    'customer_account_id' => $customerAccountId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $this->resolveKitComponentsFromProductData($merged);
    }

    /**
     * @param mixed $base
     * @param mixed $full
     * @return array<int, array<string, mixed>>
     */
    private function pickWarehouseProductsPayload($base, $full): array
    {
        $baseRows = is_array($base) ? $base : [];
        $fullRows = is_array($full) ? $full : [];
        if ($baseRows === []) return $fullRows;
        if ($fullRows === []) return $baseRows;

        return $this->warehouseProductsCompletenessScore($baseRows) >= $this->warehouseProductsCompletenessScore($fullRows)
            ? $baseRows
            : $fullRows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function warehouseProductsCompletenessScore(array $rows): int
    {
        $score = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['on_hand', 'allocated', 'reserve_inventory', 'backorder', 'reorder_amount', 'reorder_level', 'replenishment_level'] as $key) {
                if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                    $score++;
                }
            }
        }

        return $score;
    }

    /**
     * @param mixed $primary
     * @param mixed $fallback
     * @return mixed
     */
    private function pickPreferredPositiveNumeric($primary, $fallback)
    {
        $primaryNormalized = $this->normalizeNumericDisplay($primary);
        $primaryNumeric = is_numeric($primaryNormalized) ? (float) $primaryNormalized : null;
        if ($primaryNumeric !== null && $primaryNumeric > 0) {
            return $primary;
        }
        $fallbackNormalized = $this->normalizeNumericDisplay($fallback);
        $fallbackNumeric = is_numeric($fallbackNormalized) ? (float) $fallbackNormalized : null;
        if ($fallbackNumeric !== null && $fallbackNumeric > 0) {
            return $fallback;
        }
        return $primary !== null ? $primary : $fallback;
    }

    /**
     * Transfer quantity between two locations by issuing two replace mutations.
     *
     * @return array<string,mixed>
     */
    public function transferLocationQuantity(
        string $sku,
        string $warehouseId,
        string $fromLocationId,
        string $toLocationId,
        int $quantity,
        string $reason,
        ?string $customerAccountId = null
    ): array {
        if ($quantity <= 0) {
            throw new RuntimeException('Transfer quantity must be greater than zero.');
        }
        if ($fromLocationId === $toLocationId) {
            throw new RuntimeException('Source and destination locations must be different.');
        }
        $product = $this->searchProduct($sku, $warehouseId, $customerAccountId);
        if (! is_array($product)) {
            throw new RuntimeException('Product not found for transfer.');
        }
        $warehouse = null;
        foreach (($product['warehouses'] ?? []) as $wh) {
            if (is_array($wh) && (string) ($wh['warehouse_id'] ?? '') === $warehouseId) {
                $warehouse = $wh;
                break;
            }
        }
        if (! is_array($warehouse)) {
            throw new RuntimeException('Warehouse not found for transfer.');
        }
        $fromQty = null;
        $toQty = 0;
        foreach (($warehouse['locations'] ?? []) as $loc) {
            if (! is_array($loc)) {
                continue;
            }
            if ((string) ($loc['location_id'] ?? '') === $fromLocationId) {
                $fromQty = (int) ($loc['quantity'] ?? 0);
            }
            if ((string) ($loc['location_id'] ?? '') === $toLocationId) {
                $toQty = (int) ($loc['quantity'] ?? 0);
            }
        }
        if ($fromQty === null) {
            throw new RuntimeException('Source location not found.');
        }
        if ($fromQty < $quantity) {
            throw new RuntimeException('Transfer quantity exceeds source location quantity.');
        }

        $this->replaceLocationQuantity(
            $sku,
            $warehouseId,
            $fromLocationId,
            max(0, $fromQty - $quantity),
            $reason,
            $customerAccountId
        );

        return $this->replaceLocationQuantity(
            $sku,
            $warehouseId,
            $toLocationId,
            max(0, $toQty + $quantity),
            $reason,
            $customerAccountId
        );
    }

    /**
     * @param array<string,mixed> $normalized
     * @param string|null $customerAccountId
     * @return array<string,mixed>
     */
    private function enrichProductLocationsMeta(array $normalized, ?string $customerAccountId): array
    {
        $locationMetaCache = [];
        $warehouses = $normalized['warehouses'] ?? null;
        if (! is_array($warehouses) || $warehouses === []) {
            return $normalized;
        }
        foreach ($warehouses as $wIndex => $warehouse) {
            if (! is_array($warehouse)) {
                continue;
            }
            $wid = trim((string) ($warehouse['warehouse_id'] ?? ''));
            if ($wid === '') {
                continue;
            }
            $catalogById = [];
            $catalogByName = [];
            try {
                // Keep product detail location enrichment global to warehouse.
                // Passing customer_account_id here is not reliable across ShipHero accounts.
                foreach ($this->listLocations($wid, null) as $locationMeta) {
                    $idKey = strtolower(trim((string) ($locationMeta['id'] ?? '')));
                    if ($idKey !== '') {
                        $catalogById[$idKey] = $locationMeta;
                    }
                    $nameKey = strtolower(trim((string) ($locationMeta['name'] ?? '')));
                    if ($nameKey !== '') {
                        $catalogByName[$nameKey] = $locationMeta;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('shiphero.inventory.locations_meta_lookup_failed', [
                    'warehouse_id' => $wid,
                    'customer_account_id' => null,
                    'message' => $e->getMessage(),
                ]);
            }
            $locations = $warehouse['locations'] ?? null;
            if (! is_array($locations)) {
                continue;
            }
            foreach ($locations as $lIndex => $location) {
                if (! is_array($location)) {
                    continue;
                }
                $locationId = strtolower(trim((string) ($location['location_id'] ?? '')));
                $locationName = strtolower(trim((string) ($location['location_name'] ?? '')));
                $meta = null;
                if ($locationId !== '' && isset($catalogById[$locationId])) {
                    $meta = $catalogById[$locationId];
                } elseif ($locationName !== '' && isset($catalogByName[$locationName])) {
                    $meta = $catalogByName[$locationName];
                }
                if (! is_array($meta) && $locationId !== '') {
                    $directLookupId = trim((string) ($location['location_id'] ?? ''));
                    if ($directLookupId !== '') {
                        if (! array_key_exists($directLookupId, $locationMetaCache)) {
                            $locationMetaCache[$directLookupId] = $this->fetchLocationMetaById($directLookupId);
                        }
                        $meta = $locationMetaCache[$directLookupId];
                    }
                }
                if (! is_array($meta)) {
                    continue;
                }
                $lookupId = trim((string) ($meta['id'] ?? ''));
                if (($meta['pickable'] ?? null) === null || ! is_string($meta['type'] ?? null) || trim((string) ($meta['type'] ?? '')) === '') {
                    if ($lookupId !== '') {
                        if (! array_key_exists($lookupId, $locationMetaCache)) {
                            $locationMetaCache[$lookupId] = $this->fetchLocationMetaById($lookupId);
                        }
                        $extraMeta = $locationMetaCache[$lookupId];
                        if (is_array($extraMeta)) {
                            if (($meta['pickable'] ?? null) === null && array_key_exists('pickable', $extraMeta)) {
                                $meta['pickable'] = $extraMeta['pickable'];
                            }
                            if ((! is_string($meta['type'] ?? null) || trim((string) ($meta['type'] ?? '')) === '') && is_string($extraMeta['type'] ?? null)) {
                                $meta['type'] = $extraMeta['type'];
                            }
                        }
                    }
                }
                if (is_bool($meta['pickable'])) {
                    $warehouse['locations'][$lIndex]['pickable'] = $meta['pickable'];
                }
                if (is_string($meta['type']) && trim($meta['type']) !== '') {
                    $warehouse['locations'][$lIndex]['type'] = trim($meta['type']);
                }
            }
            $normalized['warehouses'][$wIndex] = $warehouse;
        }
        return $normalized;
    }

    /**
     * @return array{pickable:bool|null,type:string|null}|null
     */
    private function fetchLocationMetaById(string $locationId): ?array
    {
        $locationId = trim($locationId);
        if ($locationId === '') {
            return null;
        }
        $queries = [
            <<<'GQL'
query ShipHeroLocationMetaById($id: String!) {
  location(id: $id) {
    data {
      pickable
      type {
        name
      }
    }
  }
}
GQL,
            <<<'GQL'
query ShipHeroLocationMetaByIdScalar($id: String!) {
  location(id: $id) {
    data {
      pickable
      type
    }
  }
}
GQL,
            <<<'GQL'
query ShipHeroLocationMetaByIdIsPickable($id: String!) {
  location(id: $id) {
    data {
      is_pickable
      type
    }
  }
}
GQL,
        ];
        foreach ($queries as $graphql) {
            try {
                $json = $this->client->query($graphql, ['id' => $locationId]);
                $rawPickable = data_get($json, 'data.location.data.pickable');
                if ($rawPickable === null) {
                    $rawPickable = data_get($json, 'data.location.data.is_pickable');
                }
                $pickable = null;
                if (is_bool($rawPickable)) {
                    $pickable = $rawPickable;
                } elseif (is_int($rawPickable) || is_float($rawPickable)) {
                    $pickable = ((int) $rawPickable) === 1;
                } elseif (is_string($rawPickable)) {
                    $p = strtolower(trim($rawPickable));
                    if (in_array($p, ['1', 'true', 'yes'], true)) {
                        $pickable = true;
                    } elseif (in_array($p, ['0', 'false', 'no'], true)) {
                        $pickable = false;
                    }
                }
                $typeName = trim((string) data_get($json, 'data.location.data.type.name', ''));
                if ($typeName === '') {
                    $typeName = trim((string) data_get($json, 'data.location.data.type', ''));
                }
                return [
                    'pickable' => $pickable,
                    'type' => $typeName !== '' ? $typeName : null,
                ];
            } catch (\Throwable $e) {
                // Try next query variant.
            }
        }
        return null;
    }

    /**
     * @return array{customer_account_id: string|null}
     */
    private function customerAccountVariables(?string $customerAccountId): array
    {
        $id = is_string($customerAccountId) && trim($customerAccountId) !== ''
            ? trim($customerAccountId)
            : null;

        return ['customer_account_id' => $id];
    }

    private function looksLikeBarcode(string $term): bool
    {
        $normalized = $this->normalizeBarcodeTerm($term);

        return $normalized !== ''
            && ctype_digit($normalized)
            && strlen($normalized) >= 6;
    }

    private function normalizeBarcodeTerm(string $term): string
    {
        $normalized = preg_replace('/[\s-]+/', '', $term);

        return is_string($normalized)
            && $normalized !== ''
            ? $normalized
            : $term;
    }

    /**
     * @return array{by_id: array<string, array<string, mixed>>, by_name: array<string, array<string, mixed>>}
     */
    public function buildWarehouseLocationPickableCatalog(string $warehouseId): array
    {
        $warehouseId = trim($warehouseId);
        $cacheKey = 'shiphero.restock.location_catalog.'.md5($warehouseId);

        return Cache::remember($cacheKey, now()->addHour(), function () use ($warehouseId) {
            $catalogById = [];
            $catalogByName = [];
            foreach ($this->listLocations($warehouseId, null) as $locationMeta) {
                $idKey = strtolower(trim((string) ($locationMeta['id'] ?? '')));
                if ($idKey !== '') {
                    $catalogById[$idKey] = $locationMeta;
                }
                $nameKey = strtolower(trim((string) ($locationMeta['name'] ?? '')));
                if ($nameKey !== '') {
                    $catalogByName[$nameKey] = $locationMeta;
                }
            }

            return ['by_id' => $catalogById, 'by_name' => $catalogByName];
        });
    }

    /**
     * Paginate warehouse-scoped product inventory for the admin restock report.
     * Uses warehouse_products(warehouse_id) so each page does not load every warehouse per SKU.
     *
     * @return array{edges: list<array<string, mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null}}
     */
    public function paginateWarehouseProductsForRestock(string $warehouseId, ?string $after = null, ?int $pageSizeOverride = null): array
    {
        $warehouseId = trim($warehouseId);
        if ($warehouseId === '') {
            throw new RuntimeException('Warehouse id is required for restock report pagination.');
        }

        $first = $pageSizeOverride !== null
            ? $pageSizeOverride
            : (int) config('services.shiphero.restock_page_size', 40);
        $first = max(1, min(50, $first));
        $locationFirst = (int) config('services.shiphero.restock_location_limit', 50);
        $locationFirst = max(1, min(100, $locationFirst));
        $after = is_string($after) && trim($after) !== '' ? trim($after) : null;

        $attempt = 0;
        $maxAttempts = 5;
        while (true) {
            try {
                return $this->fetchRestockWarehouseProductsPage($warehouseId, $after, $first, $locationFirst, true);
            } catch (RuntimeException $e) {
                $attempt++;
                if (! $this->isShipHeroCreditLimitError($e->getMessage())) {
                    throw $e;
                }

                // Reduce page size first to lower per-request credit demand.
                if ($first > 5) {
                    $first = max(5, (int) floor($first / 2));
                    continue;
                }

                // If ShipHero tells us when credits refill, wait and retry a few times.
                $retrySeconds = $this->extractShipHeroCreditRetrySeconds($e->getMessage());
                if ($retrySeconds !== null && $attempt < $maxAttempts) {
                    sleep(min(30, max(1, $retrySeconds + 1)));
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * @return array{edges: list<array<string, mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null}}
     */
    private function fetchRestockWarehouseProductsPage(
        string $warehouseId,
        ?string $after,
        int $first,
        int $locationFirst,
        bool $useHideEmpty
    ): array {
        $graphql = $useHideEmpty
            ? <<<'GQL'
query ShipHeroRestockWarehouseProducts($warehouse_id: String!, $first: Int!, $after: String, $location_first: Int!) {
  warehouse_products(warehouse_id: $warehouse_id, hide_empty: true) {
    data(first: $first, after: $after) {
      pageInfo {
        hasNextPage
        endCursor
      }
      edges {
        node {
          warehouse_id
          inventory_bin
          inventory_overstock_bin
          on_hand
          active
          replenishment_level
          replenishment_max_level
          locations(first: $location_first) {
            edges {
              node {
                id
                location_id
                quantity
                location {
                  name
                  pickable
                }
              }
            }
          }
          product {
            id
            sku
            name
            active
            kit
            kit_build
            images {
              src
              position
            }
          }
        }
      }
    }
  }
}
GQL
            : <<<'GQL'
query ShipHeroRestockWarehouseProducts($warehouse_id: String!, $first: Int!, $after: String, $location_first: Int!) {
  warehouse_products(warehouse_id: $warehouse_id) {
    data(first: $first, after: $after) {
      pageInfo {
        hasNextPage
        endCursor
      }
      edges {
        node {
          warehouse_id
          inventory_bin
          inventory_overstock_bin
          on_hand
          active
          replenishment_level
          replenishment_max_level
          locations(first: $location_first) {
            edges {
              node {
                id
                location_id
                quantity
                location {
                  name
                  pickable
                }
              }
            }
          }
          product {
            id
            sku
            name
            active
            kit
            kit_build
            images {
              src
              position
            }
          }
        }
      }
    }
  }
}
GQL;

        try {
            $json = $this->client->query($graphql, [
                'warehouse_id' => $warehouseId,
                'first' => $first,
                'after' => $after,
                'location_first' => $locationFirst,
            ]);
        } catch (RuntimeException $e) {
            if ($useHideEmpty && $this->isHideEmptyArgumentError($e->getMessage())) {
                return $this->fetchRestockWarehouseProductsPage($warehouseId, $after, $first, $locationFirst, false);
            }

            throw $e;
        }

        $data = data_get($json, 'data.warehouse_products.data');
        if (! is_array($data)) {
            throw new RuntimeException('ShipHero did not return warehouse_products data for restock report.');
        }

        $edges = is_array($data['edges'] ?? null) ? $data['edges'] : [];
        $pageInfo = is_array($data['pageInfo'] ?? null) ? $data['pageInfo'] : [];

        return [
            'edges' => $edges,
            'page_info' => [
                'has_next_page' => (bool) ($pageInfo['hasNextPage'] ?? false),
                'end_cursor' => isset($pageInfo['endCursor']) && is_string($pageInfo['endCursor'])
                    ? $pageInfo['endCursor']
                    : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $productNode
     */
    public function primaryProductImageUrl(?array $productNode): ?string
    {
        if ($productNode === null) {
            return null;
        }
        $images = is_array($productNode['images'] ?? null) ? $productNode['images'] : [];
        $imageUrl = null;
        $bestPos = PHP_INT_MAX;
        foreach ($images as $img) {
            if (! is_array($img)) {
                continue;
            }
            $src = trim((string) ($img['src'] ?? ''));
            if ($src === '') {
                continue;
            }
            $pos = isset($img['position']) && is_numeric($img['position']) ? (int) $img['position'] : 999999;
            if ($imageUrl === null || $pos < $bestPos) {
                $imageUrl = $src;
                $bestPos = $pos;
            }
        }

        return $imageUrl;
    }

    private function isShipHeroCreditLimitError(string $message): bool
    {
        $lower = strtolower($message);

        return strpos($lower, 'not enough credits') !== false
            || strpos($lower, 'max allowed') !== false;
    }

    private function extractShipHeroCreditRetrySeconds(string $message): ?int
    {
        if (preg_match('/in\s+(\d+)\s+seconds?/i', $message, $m) === 1) {
            return max(1, (int) $m[1]);
        }

        return null;
    }

    private function isHideEmptyArgumentError(string $message): bool
    {
        $lower = strtolower($message);

        return strpos($lower, 'hide_empty') !== false
            || strpos($lower, 'unknown argument') !== false;
    }

    /**
     * @param  array<string, mixed>  $wp
     * @param  array<string, array<string, mixed>>  $catalogById
     * @param  array<string, array<string, mixed>>  $catalogByName
     * @return list<array{location_name: ?string, quantity: int, pickable: ?bool}>
     */
    public function enrichedLocationsForWarehouseProduct(
        array $wp,
        string $warehouseId,
        array $catalogById,
        array $catalogByName,
        bool $allowInventoryBinFallback = true
    ): array {
        $normalized = $this->normalizeLocations($wp['locations'] ?? null, $warehouseId);
        if ($normalized === [] && $allowInventoryBinFallback) {
            $normalized = $this->fallbackLocationsFromWarehouseProduct($wp, $warehouseId);
        }

        $out = [];
        foreach ($normalized as $location) {
            if (! is_array($location)) {
                continue;
            }
            $locationId = strtolower(trim((string) ($location['location_id'] ?? '')));
            $locationName = strtolower(trim((string) ($location['location_name'] ?? '')));
            $meta = null;
            if ($locationId !== '' && isset($catalogById[$locationId])) {
                $meta = $catalogById[$locationId];
            } elseif ($locationName !== '' && isset($catalogByName[$locationName])) {
                $meta = $catalogByName[$locationName];
            }
            $pickable = $location['pickable'] ?? null;
            if (is_array($meta) && array_key_exists('pickable', $meta) && is_bool($meta['pickable'])) {
                $pickable = $meta['pickable'];
            }
            $out[] = [
                'location_name' => $location['location_name'] ?? null,
                'quantity' => (int) ($location['quantity'] ?? 0),
                'pickable' => $pickable,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function extractProductImageUrl(array $node): ?string
    {
        $imageUrl = null;
        $images = is_array($node['images'] ?? null) ? $node['images'] : [];
        $bestPos = PHP_INT_MAX;
        foreach ($images as $img) {
            if (! is_array($img)) {
                continue;
            }
            $src = trim((string) ($img['src'] ?? ''));
            if ($src === '') {
                continue;
            }
            $pos = isset($img['position']) && is_numeric($img['position']) ? (int) $img['position'] : 999999;
            if ($imageUrl === null || $pos < $bestPos) {
                $imageUrl = $src;
                $bestPos = $pos;
            }
        }

        return $imageUrl;
    }
}
