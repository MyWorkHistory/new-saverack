<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\PutAwayReceivingSnapshot;
use App\Models\PutAwayReceivingSnapshotRow;
use App\Models\PutAwaySnapshot;
use App\Models\PutAwaySnapshotRow;
use App\Models\ShipHeroInventoryProductIndex;
use App\Jobs\RefreshPutAwayReceivingSnapshotJob;
use App\Support\PutAwayRowBuilder;
use RuntimeException;
use Throwable;

class PutAwayInventoryService
{
    public const CACHE_TTL_MINUTES = 30;

    public const LIST_PAGE_SIZE = 20;

    /** @var ShipHeroInventoryService */
    protected $inventory;

    /** @var InventoryRestockReportService */
    protected $restockReports;

    /** @var InventoryProductDetailCacheService */
    protected $detailCache;

    public function __construct(
        ShipHeroInventoryService $inventory,
        InventoryRestockReportService $restockReports,
        InventoryProductDetailCacheService $detailCache
    ) {
        $this->inventory = $inventory;
        $this->restockReports = $restockReports;
        $this->detailCache = $detailCache;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null}, meta: array<string, mixed>}
     */
    public function list(
        int $clientAccountId,
        ?string $query,
        int $first,
        ?string $after,
        bool $refresh = false,
        int $searchSkip = 0
    ): array {
        $account = ClientAccount::query()->findOrFail($clientAccountId);
        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            throw new RuntimeException('This account is not linked to ShipHero.');
        }

        if ($refresh) {
            $snapshot = $this->rebuildSnapshot($clientAccountId, $account, $customerId);

            return $this->paginateSnapshotRows($snapshot, $query, $first, $after);
        }

        $snapshot = PutAwaySnapshot::query()->where('client_account_id', $clientAccountId)->first();
        if (
            $snapshot !== null
            && $snapshot->status === PutAwaySnapshot::STATUS_OK
            && (int) $snapshot->row_count > 0
            && $this->snapshotHasUsableMetrics($snapshot)
        ) {
            return $this->paginateSnapshotRows($snapshot, $query, $first, $after);
        }

        return $this->listLiveFromInventory($clientAccountId, $customerId, $query, $first, $after, $searchSkip);
    }

    private function snapshotHasUsableMetrics(PutAwaySnapshot $snapshot): bool
    {
        return PutAwaySnapshotRow::query()
            ->where('put_away_snapshot_id', $snapshot->id)
            ->where(function ($q) {
                $q->where('on_hand', '>', 0)
                    ->orWhere('receiving_qty', '>', 0)
                    ->orWhere('pickable_qty', '>', 0)
                    ->orWhere('backorder', '>', 0);
            })
            ->exists();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null}, meta: array<string, mixed>}
     */
    public function refresh(int $clientAccountId): array
    {
        $account = ClientAccount::query()->findOrFail($clientAccountId);
        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            throw new RuntimeException('This account is not linked to ShipHero.');
        }

        $snapshot = $this->rebuildSnapshot($clientAccountId, $account, $customerId);

        return $this->paginateSnapshotRows($snapshot, null, self::LIST_PAGE_SIZE, null);
    }

    /**
     * Warehouse-wide Receiving inventory list (cached snapshot).
     *
     * @return array{rows: list<array<string, mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null}, meta: array<string, mixed>}
     */
    public function listReceiving(
        ?int $clientAccountId,
        ?string $query,
        int $first,
        ?string $after,
        bool $refresh = false,
        bool $receivingOnly = true
    ): array {
        $warehouseId = $this->resolveWarehouseId();

        if ($refresh) {
            $this->beginReceivingRefresh($warehouseId);
        }

        $snapshot = PutAwayReceivingSnapshot::query()->where('warehouse_id', $warehouseId)->first();
        if ($snapshot !== null) {
            $snapshot = $this->resolveStaleRunningReceivingSnapshot($snapshot);
        }

        if ($snapshot !== null && $snapshot->status === PutAwayReceivingSnapshot::STATUS_RUNNING) {
            return $this->emptyReceivingListResponse($snapshot, true);
        }

        if ($snapshot !== null && $snapshot->status === PutAwayReceivingSnapshot::STATUS_OK) {
            return $this->paginateReceivingSnapshotRows($snapshot, $clientAccountId, $query, $first, $after, $receivingOnly);
        }

        return $this->emptyReceivingListResponse($snapshot, true);
    }

    /**
     * Start async warehouse Receiving refresh (returns immediately).
     *
     * @return array<string, mixed>
     */
    public function refreshReceiving(): array
    {
        $warehouseId = $this->resolveWarehouseId();

        if ($this->isReceivingRefreshInProgress($warehouseId)) {
            return $this->receivingRefreshMeta($warehouseId);
        }

        $this->beginReceivingRefresh($warehouseId);

        return $this->receivingRefreshMeta($warehouseId);
    }

    public function isReceivingRefreshInProgress(?string $warehouseId = null): bool
    {
        $warehouseId = $warehouseId ?? $this->resolveWarehouseId();
        $snapshot = PutAwayReceivingSnapshot::query()->where('warehouse_id', $warehouseId)->first();
        if ($snapshot === null || $snapshot->status !== PutAwayReceivingSnapshot::STATUS_RUNNING) {
            return false;
        }

        $snapshot = $this->resolveStaleRunningReceivingSnapshot($snapshot);

        return $snapshot->status === PutAwayReceivingSnapshot::STATUS_RUNNING;
    }

    public function beginReceivingRefresh(?string $warehouseId = null): void
    {
        $warehouseId = $warehouseId ?? $this->resolveWarehouseId();
        if ($this->isReceivingRefreshInProgress($warehouseId)) {
            return;
        }

        $this->markReceivingRefreshRunning($warehouseId);
        try {
            $this->dispatchReceivingRefreshJob($warehouseId);
        } catch (Throwable $e) {
            $this->markReceivingRefreshFailed(
                $warehouseId,
                $e->getMessage() !== '' ? $e->getMessage() : 'Put away receiving refresh failed.'
            );
            throw $e;
        }
    }

    public function markReceivingRefreshRunning(string $warehouseId): PutAwayReceivingSnapshot
    {
        $snapshot = PutAwayReceivingSnapshot::query()->firstOrNew(['warehouse_id' => $warehouseId]);
        $snapshot->status = PutAwayReceivingSnapshot::STATUS_RUNNING;
        $snapshot->error_message = null;
        $snapshot->refresh_started_at = now();
        $snapshot->computed_at = null;
        $snapshot->duration_ms = null;
        $snapshot->save();

        if ($snapshot->id !== null) {
            PutAwayReceivingSnapshotRow::query()
                ->where('put_away_receiving_snapshot_id', $snapshot->id)
                ->delete();
        }

        return $snapshot;
    }

    public function markReceivingRefreshFailed(string $warehouseId, string $message): void
    {
        $snapshot = PutAwayReceivingSnapshot::query()->where('warehouse_id', $warehouseId)->first();
        if ($snapshot === null) {
            return;
        }

        $snapshot->status = PutAwayReceivingSnapshot::STATUS_FAILED;
        $snapshot->error_message = $message !== '' ? $message : 'Put away receiving refresh failed.';
        $snapshot->refresh_started_at = null;
        $snapshot->save();
    }

    public function runReceivingRefresh(string $warehouseId): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $this->executeReceivingSnapshotRebuild($warehouseId);
    }

    public function dispatchReceivingRefreshJob(string $warehouseId): void
    {
        $mode = strtolower(trim((string) config('services.shiphero.restock_dispatch_mode', 'after_response')));

        if ($mode === 'queue') {
            $job = new RefreshPutAwayReceivingSnapshotJob($warehouseId);
            $default = (string) config('queue.default', 'sync');
            if ($default === 'sync') {
                $async = $this->restockReports->restockQueueConnection();
                if ($async === null) {
                    throw new RuntimeException(
                        'Put away refresh requires a background queue. Set QUEUE_CONNECTION=database or redis and run: php artisan queue:work database-long --timeout=3700 --tries=1'
                    );
                }
                dispatch($job)->onConnection($async);
            } else {
                dispatch($job);
            }

            return;
        }

        $wid = $warehouseId;
        app()->terminating(function () use ($wid) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            try {
                $this->runReceivingRefresh($wid);
            } catch (Throwable $e) {
                report($e);
                $this->markReceivingRefreshFailed(
                    $wid,
                    $e->getMessage() !== '' ? $e->getMessage() : 'Put away receiving refresh failed.'
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function receivingRefreshMeta(?string $warehouseId = null): array
    {
        $warehouseId = $warehouseId ?? $this->resolveWarehouseId();
        $snapshot = PutAwayReceivingSnapshot::query()->where('warehouse_id', $warehouseId)->first();
        if ($snapshot !== null) {
            $snapshot = $this->resolveStaleRunningReceivingSnapshot($snapshot);
        }

        return [
            'warehouse_id' => $warehouseId,
            'status' => $snapshot !== null ? $snapshot->status : 'missing',
            'computed_at' => $snapshot !== null && $snapshot->computed_at !== null
                ? $snapshot->computed_at->toIso8601String()
                : null,
            'row_count' => $snapshot !== null ? (int) $snapshot->row_count : 0,
            'error_message' => $snapshot !== null ? $snapshot->error_message : null,
            'duration_ms' => $snapshot !== null ? $snapshot->duration_ms : null,
            'skipped_unresolved_account' => $snapshot !== null
                ? (int) $snapshot->skipped_unresolved_account
                : 0,
            'refresh_started_at' => $snapshot !== null && $snapshot->refresh_started_at !== null
                ? $snapshot->refresh_started_at->toIso8601String()
                : null,
        ];
    }

    /**
     * Single source of truth for put-away row metrics (list + detail).
     *
     * @return array<string, mixed>|null
     */
    public function rowForSku(int $clientAccountId, string $sku, bool $refreshProduct = false): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $account = ClientAccount::query()->findOrFail($clientAccountId);
        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            throw new RuntimeException('This account is not linked to ShipHero.');
        }

        $snapRow = $this->findSnapshotRow($clientAccountId, $sku);
        if ($snapRow !== null && $this->snapshotRowHasUsableMetrics($snapRow)) {
            return $this->snapshotRowToPayload($snapRow, $clientAccountId);
        }

        $index = $this->indexFieldsForSku($clientAccountId, $sku);
        if ($refreshProduct) {
            $product = $this->inventory->getProductDetailBySku($sku, null, $customerId, false);
            if ($product !== null) {
                $this->detailCache->putProduct($clientAccountId, $sku, $product);
            }
        } else {
            $product = $this->resolveProductDetailForPutAway($clientAccountId, $customerId, $sku);
        }

        return $this->buildPutAwayRowPayload($clientAccountId, $sku, $product, $index);
    }

    private function isFresh(PutAwaySnapshot $snapshot): bool
    {
        if ($snapshot->status !== PutAwaySnapshot::STATUS_OK) {
            return false;
        }
        if ($snapshot->computed_at === null) {
            return false;
        }

        return $snapshot->computed_at->gte(now()->subMinutes(self::CACHE_TTL_MINUTES));
    }

    /**
     * Fast list path (no snapshot): same inventory index pagination as the inventory list page.
     * Location metrics use local detail cache only — no per-SKU ShipHero calls (use Refresh for full counts).
     *
     * @return array{rows: list<array<string, mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null, next_search_skip?: int|null}, meta: array<string, mixed>}
     */
    private function listLiveFromInventory(
        int $clientAccountId,
        string $customerId,
        ?string $query,
        int $first,
        ?string $after,
        int $searchSkip = 0
    ): array {
        $searchQuery = is_string($query) ? trim($query) : '';
        $graphqlAfter = null;

        if ($searchQuery === '' && is_string($after) && $after !== '') {
            if (preg_match('/^idx:(\d+)$/', $after, $m)) {
                $searchSkip = max(0, (int) $m[1]);
            } elseif (ctype_digit($after)) {
                $searchSkip = max(0, (int) $after);
            } else {
                $graphqlAfter = $after;
            }
        }

        $page = $this->inventory->listInventoryRows(
            $customerId,
            $first,
            $graphqlAfter,
            'no',
            'active',
            $searchQuery !== '' ? $searchQuery : null,
            $searchSkip,
            $clientAccountId,
            false,
            false
        );

        $rows = [];
        foreach ($page['rows'] ?? [] as $invRow) {
            if (! is_array($invRow)) {
                continue;
            }
            $rows[] = [
                'sku' => trim((string) ($invRow['sku'] ?? '')),
                'name' => (string) ($invRow['name'] ?? ''),
                'barcode' => isset($invRow['barcode']) ? (string) $invRow['barcode'] : null,
                'image_url' => isset($invRow['image_url']) ? (string) $invRow['image_url'] : null,
                'on_hand' => (int) round((float) ($invRow['on_hand'] ?? 0)),
                'backorder' => (int) round((float) ($invRow['backorder'] ?? 0)),
                'client_account_id' => $clientAccountId,
            ];
        }
        $rows = $this->hydrateLivePutAwayRows($clientAccountId, $rows);

        $pageInfo = is_array($page['page_info'] ?? null) ? $page['page_info'] : [];
        $metaSource = 'inventory_list';

        return [
            'rows' => $rows,
            'page_info' => [
                'has_next_page' => (bool) ($pageInfo['has_next_page'] ?? false),
                'end_cursor' => isset($pageInfo['end_cursor']) && is_string($pageInfo['end_cursor']) && $pageInfo['end_cursor'] !== ''
                    ? $pageInfo['end_cursor']
                    : null,
                'next_search_skip' => isset($pageInfo['next_search_skip']) && is_numeric($pageInfo['next_search_skip'])
                    ? (int) $pageInfo['next_search_skip']
                    : null,
            ],
            'meta' => [
                'computed_at' => null,
                'stale' => true,
                'row_count' => count($rows),
                'filtered_count' => count($rows),
                'status' => 'live',
                'error_message' => null,
                'duration_ms' => null,
                'source' => $metaSource,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateLivePutAwayRows(int $clientAccountId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $built = $this->rowForSku($clientAccountId, $sku, false);
            if ($built === null) {
                continue;
            }
            if (! empty($row['name'])) {
                $built['name'] = (string) $row['name'];
            }
            $out[] = $built;
        }

        return $out;
    }

    private function snapshotRowHasUsableMetrics(PutAwaySnapshotRow $row): bool
    {
        return (int) $row->on_hand > 0
            || (int) $row->receiving_qty > 0
            || (int) $row->pickable_qty > 0
            || (int) $row->backorder > 0;
    }

    private function findSnapshotRow(int $clientAccountId, string $sku): ?PutAwaySnapshotRow
    {
        $snapshot = PutAwaySnapshot::query()
            ->where('client_account_id', $clientAccountId)
            ->where('status', PutAwaySnapshot::STATUS_OK)
            ->first();
        if ($snapshot === null) {
            return null;
        }

        return PutAwaySnapshotRow::query()
            ->where('put_away_snapshot_id', $snapshot->id)
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower(trim($sku))])
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotRowToPayload(PutAwaySnapshotRow $row, int $clientAccountId): array
    {
        return [
            'sku' => $row->sku,
            'name' => $row->name,
            'barcode' => $row->barcode,
            'image_url' => $row->image_url,
            'receiving_qty' => (int) $row->receiving_qty,
            'pickable_qty' => (int) $row->pickable_qty,
            'non_pickable_qty' => (int) $row->non_pickable_qty,
            'on_hand' => (int) $row->on_hand,
            'backorder' => (int) $row->backorder,
            'client_account_id' => $clientAccountId,
        ];
    }

    /**
     * @return array{name: string, barcode: ?string, image_url: ?string, on_hand: int, backorder: int}
     */
    private function indexFieldsForSku(int $clientAccountId, string $sku): array
    {
        $row = ShipHeroInventoryProductIndex::query()
            ->where('client_account_id', $clientAccountId)
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower(trim($sku))])
            ->orderByDesc('on_hand')
            ->first();

        if ($row === null) {
            return [
                'name' => '',
                'barcode' => null,
                'image_url' => null,
                'on_hand' => 0,
                'backorder' => 0,
            ];
        }

        return [
            'name' => (string) ($row->name ?? ''),
            'barcode' => $row->barcode !== null ? trim((string) $row->barcode) : null,
            'image_url' => $row->image_url !== null ? trim((string) $row->image_url) : null,
            'on_hand' => (int) round((float) $row->on_hand),
            'backorder' => (int) round((float) $row->backorder),
        ];
    }

    /**
     * @param  array{name: string, barcode: ?string, image_url: ?string, on_hand: int, backorder: int}  $index
     * @return array<string, mixed>
     */
    private function buildPutAwayRowPayload(int $clientAccountId, string $sku, ?array $product, array $index): array
    {
        $locations = PutAwayRowBuilder::locationsFromProductDetail($product);
        $metrics = is_array($product['metrics'] ?? null) ? $product['metrics'] : [];

        $onHand = (int) round((float) ($metrics['on_hand'] ?? 0));
        if ($onHand <= 0) {
            $onHand = (int) ($index['on_hand'] ?? 0);
        }
        $backorder = (int) round((float) ($metrics['backorder'] ?? 0));
        if ($backorder <= 0) {
            $backorder = (int) ($index['backorder'] ?? 0);
        }

        $built = PutAwayRowBuilder::buildRow(
            $sku,
            (string) (($index['name'] ?? '') !== '' ? $index['name'] : ($product['name'] ?? $sku)),
            ($index['barcode'] ?? null) ?: (isset($product['barcode']) ? (string) $product['barcode'] : null),
            ($index['image_url'] ?? null) ?: (isset($product['image_url']) ? (string) $product['image_url'] : null),
            $locations,
            $onHand,
            $backorder
        );
        $built['client_account_id'] = $clientAccountId;

        return $built;
    }

    /**
     * Match inventory product detail: load by account SKU without warehouse filter.
     *
     * @return array<string, mixed>|null
     */
    private function resolveProductDetailForPutAway(int $clientAccountId, string $customerId, string $sku): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $product = $this->detailCache->getCachedProduct($clientAccountId, $sku);
        if ($product !== null) {
            return $product;
        }

        $product = $this->inventory->getProductDetailBySku($sku, null, $customerId, false);
        if ($product !== null) {
            $this->detailCache->putProduct($clientAccountId, $sku, $product);
        }

        return $product;
    }

    private function rebuildSnapshot(int $clientAccountId, ClientAccount $account, string $customerId): PutAwaySnapshot
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $started = microtime(true);
        $warehouseId = $this->resolveWarehouseId();

        $snapshot = PutAwaySnapshot::query()->firstOrNew(['client_account_id' => $clientAccountId]);
        $snapshot->warehouse_id = $warehouseId;
        $snapshot->status = PutAwaySnapshot::STATUS_RUNNING;
        $snapshot->error_message = null;
        $snapshot->save();

        try {
            PutAwaySnapshotRow::query()->where('put_away_snapshot_id', $snapshot->id)->delete();
            $this->detailCache->clearForClientAccount($clientAccountId);

            $this->ensureAccountIndex($clientAccountId, $customerId);
            $allowList = $this->loadAccountSkuAllowList($clientAccountId);
            $locationDataBySku = $this->scanWarehouseLocationsForSkus($warehouseId, $allowList);

            $builtRows = [];
            foreach ($allowList as $skuKey => $indexRow) {
                $locData = $locationDataBySku[$skuKey] ?? null;
                $locations = is_array($locData['locations'] ?? null) ? $locData['locations'] : [];
                $onHand = isset($locData['on_hand'])
                    ? (int) $locData['on_hand']
                    : (int) round((float) ($indexRow['on_hand'] ?? 0));
                $backorder = (int) round((float) ($indexRow['backorder'] ?? 0));

                if ($locations === []) {
                    $sku = (string) ($indexRow['sku'] ?? '');
                    $product = $this->inventory->getProductDetailBySku($sku, null, $customerId, false);
                    if ($product !== null) {
                        $this->detailCache->putProduct($clientAccountId, $sku, $product);
                    }
                    $locations = PutAwayRowBuilder::locationsFromProductDetail($product);
                    $metrics = is_array($product['metrics'] ?? null) ? $product['metrics'] : [];
                    if ($metrics !== []) {
                        $onHand = (int) round((float) ($metrics['on_hand'] ?? $onHand));
                        $backorder = (int) round((float) ($metrics['backorder'] ?? $backorder));
                    }
                }

                $builtRows[] = PutAwayRowBuilder::buildRow(
                    (string) $indexRow['sku'],
                    (string) ($indexRow['name'] ?? $indexRow['sku']),
                    isset($indexRow['barcode']) ? (string) $indexRow['barcode'] : null,
                    isset($indexRow['image_url']) ? (string) $indexRow['image_url'] : null,
                    $locations,
                    $onHand,
                    $backorder
                );
            }

            $now = now();
            foreach (array_chunk($builtRows, 200) as $chunk) {
                $insert = [];
                foreach ($chunk as $row) {
                    $insert[] = [
                        'put_away_snapshot_id' => $snapshot->id,
                        'sku' => $row['sku'],
                        'name' => $row['name'],
                        'barcode' => $row['barcode'],
                        'image_url' => $row['image_url'],
                        'receiving_qty' => $row['receiving_qty'],
                        'pickable_qty' => $row['pickable_qty'],
                        'non_pickable_qty' => $row['non_pickable_qty'],
                        'on_hand' => $row['on_hand'],
                        'backorder' => $row['backorder'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($insert !== []) {
                    PutAwaySnapshotRow::query()->insert($insert);
                }
            }

            $snapshot->computed_at = now();
            $snapshot->row_count = count($builtRows);
            $snapshot->status = PutAwaySnapshot::STATUS_OK;
            $snapshot->duration_ms = (int) round((microtime(true) - $started) * 1000);
            $snapshot->error_message = null;
            $snapshot->save();

            return $snapshot->fresh();
        } catch (Throwable $e) {
            $snapshot->status = PutAwaySnapshot::STATUS_FAILED;
            $snapshot->error_message = $e->getMessage();
            $snapshot->duration_ms = (int) round((microtime(true) - $started) * 1000);
            $snapshot->save();
            throw $e;
        }
    }

    private function ensureAccountIndex(int $clientAccountId, string $customerId): void
    {
        $exists = ShipHeroInventoryProductIndex::query()
            ->where('client_account_id', $clientAccountId)
            ->exists();
        if ($exists) {
            return;
        }

        $after = null;
        $firstPage = true;
        do {
            $page = $this->inventory->listInventoryRows(
                $customerId,
                50,
                $after,
                'all',
                'active',
                null,
                0,
                $clientAccountId,
                false,
                $firstPage
            );
            $firstPage = false;
            $pageInfo = is_array($page['page_info'] ?? null) ? $page['page_info'] : [];
            $hasNext = (bool) ($pageInfo['has_next_page'] ?? false);
            $after = isset($pageInfo['end_cursor']) && is_string($pageInfo['end_cursor']) && $pageInfo['end_cursor'] !== ''
                ? $pageInfo['end_cursor']
                : null;
        } while ($hasNext && $after !== null);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadAccountSkuAllowList(int $clientAccountId): array
    {
        $rows = ShipHeroInventoryProductIndex::query()
            ->where('client_account_id', $clientAccountId)
            ->where('product_active', true)
            ->where('kit', false)
            ->where('kit_build', false)
            ->orderBy('sku')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $sku = trim((string) $row->sku);
            if ($sku === '') {
                continue;
            }
            $key = mb_strtolower($sku);
            if (isset($out[$key])) {
                $out[$key]['on_hand'] = max(
                    (float) ($out[$key]['on_hand'] ?? 0),
                    (float) $row->on_hand
                );
                $out[$key]['backorder'] = max(
                    (float) ($out[$key]['backorder'] ?? 0),
                    (float) $row->backorder
                );
                continue;
            }
            $out[$key] = [
                'sku' => $sku,
                'name' => (string) ($row->name ?? $sku),
                'barcode' => $row->barcode !== null ? trim((string) $row->barcode) : null,
                'image_url' => $row->image_url !== null ? trim((string) $row->image_url) : null,
                'on_hand' => (float) $row->on_hand,
                'backorder' => (float) $row->backorder,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $allowList
     * @return array<string, array{locations: list<array<string, mixed>>, on_hand: int}>
     */
    private function scanWarehouseLocationsForSkus(string $warehouseId, array $allowList, bool $stopWhenComplete = false): array
    {
        if ($allowList === []) {
            return [];
        }

        $catalog = $this->inventory->buildWarehouseLocationPickableCatalog($warehouseId);
        $catalogById = is_array($catalog['by_id'] ?? null) ? $catalog['by_id'] : [];
        $catalogByName = is_array($catalog['by_name'] ?? null) ? $catalog['by_name'] : [];

        $out = [];
        $remaining = $stopWhenComplete ? count($allowList) : null;
        $after = null;
        do {
            $pageResult = $this->inventory->paginateWarehouseProductsForRestock($warehouseId, $after);
            $edges = is_array($pageResult['edges'] ?? null) ? $pageResult['edges'] : [];
            foreach ($edges as $edge) {
                $wp = is_array($edge['node'] ?? null) ? $edge['node'] : null;
                if ($wp === null || ($wp['active'] ?? null) === false) {
                    continue;
                }
                $product = is_array($wp['product'] ?? null) ? $wp['product'] : null;
                if ($product === null || ($product['active'] ?? null) === false) {
                    continue;
                }
                if (($product['kit'] ?? false) === true || ($product['kit_build'] ?? false) === true) {
                    continue;
                }
                $sku = trim((string) ($product['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }
                $skuKey = mb_strtolower($sku);
                if (! isset($allowList[$skuKey]) || isset($out[$skuKey])) {
                    continue;
                }

                $locations = $this->inventory->enrichedLocationsForWarehouseProduct(
                    $wp,
                    $warehouseId,
                    $catalogById,
                    $catalogByName,
                    false
                );
                $out[$skuKey] = [
                    'locations' => $locations,
                    'on_hand' => (int) round((float) ($wp['on_hand'] ?? 0)),
                ];

                if ($stopWhenComplete && $remaining !== null) {
                    $remaining--;
                    if ($remaining <= 0) {
                        break 2;
                    }
                }
            }

            $pageInfo = is_array($pageResult['page_info'] ?? null) ? $pageResult['page_info'] : [];
            $hasNext = (bool) ($pageInfo['has_next_page'] ?? false);
            $after = isset($pageInfo['end_cursor']) && is_string($pageInfo['end_cursor']) && $pageInfo['end_cursor'] !== ''
                ? $pageInfo['end_cursor']
                : null;
        } while ($hasNext && $after !== null);

        return $out;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null}, meta: array<string, mixed>}
     */
    private function paginateSnapshotRows(
        PutAwaySnapshot $snapshot,
        ?string $query,
        int $first,
        ?string $after
    ): array {
        $first = max(1, min(200, $first));
        $offset = 0;
        if (is_string($after) && $after !== '' && ctype_digit($after)) {
            $offset = max(0, (int) $after);
        }

        $q = is_string($query) ? trim($query) : '';
        $base = PutAwaySnapshotRow::query()->where('put_away_snapshot_id', $snapshot->id);
        if ($q !== '') {
            $like = '%'.mb_strtolower($q).'%';
            $base->where(function ($builder) use ($like) {
                $builder
                    ->whereRaw('LOWER(sku) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(barcode, \'\')) LIKE ?', [$like]);
            });
        }

        $total = (clone $base)->count();
        $items = $base->orderBy('sku')->offset($offset)->limit($first + 1)->get();
        $hasNext = $items->count() > $first;
        if ($hasNext) {
            $items = $items->slice(0, $first);
        }

        $rows = $items->map(static function (PutAwaySnapshotRow $row) use ($snapshot) {
            return [
                'sku' => $row->sku,
                'name' => $row->name,
                'barcode' => $row->barcode,
                'image_url' => $row->image_url,
                'receiving_qty' => (int) $row->receiving_qty,
                'pickable_qty' => (int) $row->pickable_qty,
                'non_pickable_qty' => (int) $row->non_pickable_qty,
                'on_hand' => (int) $row->on_hand,
                'backorder' => (int) $row->backorder,
                'client_account_id' => (int) $snapshot->client_account_id,
            ];
        })->values()->all();

        $nextOffset = $offset + $first;

        return [
            'rows' => $rows,
            'page_info' => [
                'has_next_page' => $hasNext,
                'end_cursor' => $hasNext ? (string) $nextOffset : null,
            ],
            'meta' => [
                'computed_at' => optional($snapshot->computed_at)->toIso8601String(),
                'stale' => ! $this->isFresh($snapshot),
                'row_count' => (int) $snapshot->row_count,
                'filtered_count' => $total,
                'status' => $snapshot->status,
                'error_message' => $snapshot->error_message,
                'duration_ms' => $snapshot->duration_ms,
                'source' => 'snapshot',
            ],
        ];
    }

    private function resolveWarehouseId(): string
    {
        $configured = config('services.shiphero.put_away_warehouse_id');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return $this->restockReports->resolveWarehouseIdForApi(null);
    }

    private function isReceivingFresh(PutAwayReceivingSnapshot $snapshot): bool
    {
        if ($snapshot->status !== PutAwayReceivingSnapshot::STATUS_OK) {
            return false;
        }
        if ($snapshot->computed_at === null) {
            return false;
        }

        return $snapshot->computed_at->gte(now()->subMinutes(self::CACHE_TTL_MINUTES));
    }

    private function resolveStaleRunningReceivingSnapshot(PutAwayReceivingSnapshot $snapshot): PutAwayReceivingSnapshot
    {
        if ($snapshot->status !== PutAwayReceivingSnapshot::STATUS_RUNNING) {
            return $snapshot;
        }

        $lastTouch = $snapshot->updated_at ?? $snapshot->refresh_started_at;
        $stallMinutes = max(3, (int) config('services.shiphero.restock_stall_minutes', 10));
        if ($lastTouch !== null && $lastTouch->lessThan(now()->subMinutes($stallMinutes))) {
            $snapshot->status = PutAwayReceivingSnapshot::STATUS_FAILED;
            $snapshot->error_message = 'Refresh stalled (no progress). Click Refresh to retry. If this keeps happening, set SHIPHERO_RESTOCK_DISPATCH_MODE=queue and run a queue worker.';
            $snapshot->refresh_started_at = null;
            $snapshot->save();
        }

        return $snapshot;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null}, meta: array<string, mixed>}
     */
    private function emptyReceivingListResponse(?PutAwayReceivingSnapshot $snapshot, bool $stale): array
    {
        $status = $snapshot !== null ? $snapshot->status : 'missing';

        return [
            'rows' => [],
            'page_info' => [
                'has_next_page' => false,
                'end_cursor' => null,
            ],
            'meta' => [
                'computed_at' => $snapshot !== null && $snapshot->computed_at !== null
                    ? $snapshot->computed_at->toIso8601String()
                    : null,
                'stale' => $stale || $status === PutAwayReceivingSnapshot::STATUS_RUNNING,
                'row_count' => $snapshot !== null ? (int) $snapshot->row_count : 0,
                'filtered_count' => 0,
                'status' => $status,
                'error_message' => $snapshot !== null ? $snapshot->error_message : null,
                'duration_ms' => $snapshot !== null ? $snapshot->duration_ms : null,
                'source' => 'snapshot',
                'skipped_unresolved_account' => $snapshot !== null
                    ? (int) $snapshot->skipped_unresolved_account
                    : 0,
            ],
        ];
    }

    private function executeReceivingSnapshotRebuild(string $warehouseId): PutAwayReceivingSnapshot
    {
        $started = microtime(true);

        $snapshot = PutAwayReceivingSnapshot::query()->where('warehouse_id', $warehouseId)->first();
        if ($snapshot === null) {
            $snapshot = $this->markReceivingRefreshRunning($warehouseId);
        }

        try {
            PutAwayReceivingSnapshotRow::query()
                ->where('put_away_receiving_snapshot_id', $snapshot->id)
                ->delete();

            $catalog = $this->inventory->buildWarehouseLocationPickableCatalog($warehouseId);
            $catalogById = is_array($catalog['by_id'] ?? null) ? $catalog['by_id'] : [];
            $catalogByName = is_array($catalog['by_name'] ?? null) ? $catalog['by_name'] : [];

            $builtRows = [];
            $skippedUnresolved = 0;
            $after = null;
            do {
                $pageResult = $this->inventory->paginateWarehouseProductsForRestock($warehouseId, $after);
                $edges = is_array($pageResult['edges'] ?? null) ? $pageResult['edges'] : [];
                foreach ($edges as $edge) {
                    $wp = is_array($edge['node'] ?? null) ? $edge['node'] : null;
                    if ($wp === null || ($wp['active'] ?? null) === false) {
                        continue;
                    }
                    $product = is_array($wp['product'] ?? null) ? $wp['product'] : null;
                    if ($product === null || ($product['active'] ?? null) === false) {
                        continue;
                    }
                    if (($product['kit'] ?? false) === true || ($product['kit_build'] ?? false) === true) {
                        continue;
                    }
                    $sku = trim((string) ($product['sku'] ?? ''));
                    if ($sku === '') {
                        continue;
                    }

                    $locations = $this->inventory->enrichedLocationsForWarehouseProduct(
                        $wp,
                        $warehouseId,
                        $catalogById,
                        $catalogByName,
                        false
                    );
                    $onHand = (int) round((float) ($wp['on_hand'] ?? 0));
                    $backorder = (int) round((float) ($product['backorder'] ?? 0));

                    $row = PutAwayRowBuilder::buildRow(
                        $sku,
                        (string) ($product['name'] ?? $sku),
                        isset($product['barcode']) ? (string) $product['barcode'] : null,
                        isset($product['thumbnail']) ? (string) $product['thumbnail'] : (
                            isset($product['image_url']) ? (string) $product['image_url'] : null
                        ),
                        $locations,
                        $onHand,
                        $backorder
                    );

                    if ((int) ($row['receiving_qty'] ?? 0) <= 0) {
                        continue;
                    }

                    $clientAccountId = $this->resolveClientAccountIdForSku($sku);
                    if ($clientAccountId === null) {
                        $skippedUnresolved++;

                        continue;
                    }

                    $row['client_account_id'] = $clientAccountId;
                    $builtRows[] = $row;
                }

                $pageInfo = is_array($pageResult['page_info'] ?? null) ? $pageResult['page_info'] : [];
                $hasNext = (bool) ($pageInfo['has_next_page'] ?? false);
                $after = isset($pageInfo['end_cursor']) && is_string($pageInfo['end_cursor']) && $pageInfo['end_cursor'] !== ''
                    ? $pageInfo['end_cursor']
                    : null;
            } while ($hasNext && $after !== null);

            $now = now();
            foreach (array_chunk($builtRows, 200) as $chunk) {
                $insert = [];
                foreach ($chunk as $row) {
                    $insert[] = [
                        'put_away_receiving_snapshot_id' => $snapshot->id,
                        'client_account_id' => $row['client_account_id'],
                        'sku' => $row['sku'],
                        'name' => $row['name'],
                        'barcode' => $row['barcode'],
                        'image_url' => $row['image_url'],
                        'receiving_qty' => $row['receiving_qty'],
                        'pickable_qty' => $row['pickable_qty'],
                        'non_pickable_qty' => $row['non_pickable_qty'],
                        'on_hand' => $row['on_hand'],
                        'backorder' => $row['backorder'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($insert !== []) {
                    PutAwayReceivingSnapshotRow::query()->insert($insert);
                }
            }

            $snapshot->computed_at = now();
            $snapshot->row_count = count($builtRows);
            $snapshot->skipped_unresolved_account = $skippedUnresolved;
            $snapshot->status = PutAwayReceivingSnapshot::STATUS_OK;
            $snapshot->duration_ms = (int) round((microtime(true) - $started) * 1000);
            $snapshot->error_message = null;
            $snapshot->refresh_started_at = null;
            $snapshot->save();

            return $snapshot->fresh();
        } catch (Throwable $e) {
            $snapshot->status = PutAwayReceivingSnapshot::STATUS_FAILED;
            $snapshot->error_message = $e->getMessage();
            $snapshot->duration_ms = (int) round((microtime(true) - $started) * 1000);
            $snapshot->refresh_started_at = null;
            $snapshot->save();
            throw $e;
        }
    }

    private function resolveClientAccountIdForSku(string $sku): ?int
    {
        $skuSearch = mb_strtolower(trim($sku));
        if ($skuSearch === '') {
            return null;
        }

        $indexRow = ShipHeroInventoryProductIndex::query()
            ->where('sku_search', $skuSearch)
            ->orderByDesc('synced_at')
            ->first();

        if ($indexRow === null) {
            $indexRow = ShipHeroInventoryProductIndex::query()
                ->whereRaw('LOWER(sku) = ?', [$skuSearch])
                ->orderByDesc('synced_at')
                ->first();
        }

        if ($indexRow !== null) {
            return (int) $indexRow->client_account_id;
        }

        $customerId = $this->inventory->lookupShipHeroCustomerAccountIdForSku($sku);
        if ($customerId !== null) {
            $accountId = ClientAccount::query()
                ->where('shiphero_customer_account_id', $customerId)
                ->value('id');
            if ($accountId !== null) {
                return (int) $accountId;
            }
        }

        $customerId = $this->inventory->fetchProductAccountIdBySku($sku);
        if ($customerId !== null) {
            $accountId = ClientAccount::query()
                ->where('shiphero_customer_account_id', $customerId)
                ->value('id');
            if ($accountId !== null) {
                return (int) $accountId;
            }
        }

        return null;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null}, meta: array<string, mixed>}
     */
    private function paginateReceivingSnapshotRows(
        PutAwayReceivingSnapshot $snapshot,
        ?int $clientAccountId,
        ?string $query,
        int $first,
        ?string $after,
        bool $receivingOnly = true
    ): array {
        $first = max(1, min(200, $first));
        $offset = 0;
        if (is_string($after) && $after !== '' && ctype_digit($after)) {
            $offset = max(0, (int) $after);
        }

        $q = is_string($query) ? trim($query) : '';
        $base = PutAwayReceivingSnapshotRow::query()
            ->where('put_away_receiving_snapshot_id', $snapshot->id);
        if ($receivingOnly) {
            $base->where('receiving_qty', '>', 0);
        }
        if ($clientAccountId !== null && $clientAccountId > 0) {
            $base->where('client_account_id', $clientAccountId);
        }
        if ($q !== '') {
            $like = '%'.mb_strtolower($q).'%';
            $base->where(function ($builder) use ($like) {
                $builder
                    ->whereRaw('LOWER(sku) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(barcode, \'\')) LIKE ?', [$like]);
            });
        }

        $total = (clone $base)->count();
        $items = $base->orderBy('sku')->offset($offset)->limit($first + 1)->get();
        $hasNext = $items->count() > $first;
        if ($hasNext) {
            $items = $items->slice(0, $first);
        }

        $rows = $items->map(static function (PutAwayReceivingSnapshotRow $row) {
            return [
                'sku' => $row->sku,
                'name' => $row->name,
                'barcode' => $row->barcode,
                'image_url' => $row->image_url,
                'receiving_qty' => (int) $row->receiving_qty,
                'pickable_qty' => (int) $row->pickable_qty,
                'non_pickable_qty' => (int) $row->non_pickable_qty,
                'on_hand' => (int) $row->on_hand,
                'backorder' => (int) $row->backorder,
                'client_account_id' => $row->client_account_id !== null ? (int) $row->client_account_id : null,
            ];
        })->values()->all();

        $nextOffset = $offset + $first;

        return [
            'rows' => $rows,
            'page_info' => [
                'has_next_page' => $hasNext,
                'end_cursor' => $hasNext ? (string) $nextOffset : null,
            ],
            'meta' => [
                'computed_at' => optional($snapshot->computed_at)->toIso8601String(),
                'stale' => ! $this->isReceivingFresh($snapshot),
                'row_count' => (int) $snapshot->row_count,
                'filtered_count' => $total,
                'status' => $snapshot->status,
                'error_message' => $snapshot->error_message,
                'duration_ms' => $snapshot->duration_ms,
                'source' => 'snapshot',
                'skipped_unresolved_account' => (int) $snapshot->skipped_unresolved_account,
            ],
        ];
    }
}
