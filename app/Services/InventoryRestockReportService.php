<?php

namespace App\Services;

use App\Jobs\RefreshInventoryRestockReportJob;
use App\Models\InventoryRestockSnapshot;
use App\Support\InventoryRestockRowBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class InventoryRestockReportService
{
    /** @var ShipHeroInventoryService */
    protected $inventory;

    /** @var array<string, list<array<string, mixed>>> */
    private $pendingRefreshRowsByWarehouse = [];

    /** @var array<string, array{by_id: array<string, array<string, mixed>>, by_name: array<string, array<string, mixed>>}> */
    private $refreshCatalogCache = [];

    public function __construct(ShipHeroInventoryService $inventory)
    {
        $this->inventory = $inventory;
    }

    public function staleMinutes(): int
    {
        return max(5, (int) config('services.shiphero.restock_stale_minutes', 20));
    }

    /** @var list<string> */
    private const SNAPSHOT_META_COLUMNS = [
        'id',
        'warehouse_id',
        'computed_at',
        'row_count',
        'status',
        'error_message',
        'duration_ms',
        'refresh_started_at',
        'progress_page',
        'scan_stats',
        'created_at',
        'updated_at',
    ];

    public function maxPickableQty(): int
    {
        return max(0, (int) config('services.shiphero.restock_max_pickable_qty', 2));
    }

    public function chunkPages(): int
    {
        return max(1, min(100, (int) config('services.shiphero.restock_chunk_pages', 15)));
    }

    /** New matches to collect per Refresh or Load More (0 = unlimited, for scheduled jobs). */
    public function matchBatchSize(): int
    {
        return max(0, (int) config('services.shiphero.restock_match_batch_size', 20));
    }

    public function scanSafetyMaxPages(): int
    {
        return max(10, (int) config('services.shiphero.restock_scan_safety_max_pages', 200));
    }

    public function useUnlimitedMatchBatch(): void
    {
        config(['services.shiphero.restock_match_batch_size' => 0]);
    }

    public function stallMinutes(): int
    {
        return max(3, (int) config('services.shiphero.restock_stall_minutes', 10));
    }

    public function isRefreshInProgress(?string $warehouseId = null): bool
    {
        $wid = $this->configuredWarehouseId($warehouseId) ?? $this->resolveWarehouseId($warehouseId);
        $row = $this->findSnapshotRow($wid, false);

        if ($row === null || $row->status !== InventoryRestockSnapshot::STATUS_RUNNING) {
            return false;
        }

        $row = $this->resolveStaleRunningSnapshot($row);

        return $row->status === InventoryRestockSnapshot::STATUS_RUNNING;
    }

    /**
     * @return array<string, mixed>
     */
    public function markRefreshRunning(?string $warehouseId = null): array
    {
        $wid = $this->resolveWarehouseId($warehouseId);

        $existing = $this->findSnapshotRow($wid, false);
        if ($existing !== null) {
            $this->resolveStaleRunningSnapshot($existing);
        }

        $now = Carbon::now();
        $row = InventoryRestockSnapshot::query()->updateOrCreate(
            ['warehouse_id' => $wid],
            [
                'status' => InventoryRestockSnapshot::STATUS_RUNNING,
                'error_message' => null,
                'refresh_started_at' => $now,
                'progress_page' => 0,
                'scan_cursor' => null,
                'computed_at' => null,
                'duration_ms' => null,
                'rows' => [],
                'row_count' => 0,
                'scan_stats' => null,
            ]
        );

        return $this->serializeSnapshot($row, false);
    }

    /**
     * Dispatch background work for one full refresh (all paginated chunks).
     */
    public function dispatchRefreshJob(?string $warehouseId = null): void
    {
        $this->dispatchFullRefresh($warehouseId);
    }

    /**
     * Run every paginated chunk until the warehouse scan completes.
     */
    public function runFullRefreshUntilDone(?string $warehouseId = null): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $wid = $this->resolveWarehouseId($warehouseId);

        try {
            $result = $this->refreshNextChunk($warehouseId);
            if ($this->matchBatchSize() <= 0) {
                while ($result['has_more'] ?? false) {
                    $result = $this->refreshNextChunk($warehouseId);
                }
            }
        } finally {
            $this->clearRefreshWorkingState($wid);
        }
    }

    /**
     * Queue a job or run after the HTTP response (PHP-FPM safe).
     */
    public function dispatchFullRefresh(?string $warehouseId = null): void
    {
        $mode = strtolower(trim((string) config('services.shiphero.restock_dispatch_mode', 'after_response')));

        if ($mode === 'queue') {
            $job = new RefreshInventoryRestockReportJob($warehouseId);
            $default = (string) config('queue.default', 'sync');
            if ($default === 'sync') {
                $async = $this->restockQueueConnection();
                if ($async === null) {
                    throw new RuntimeException(
                        'Restock refresh requires a background queue. Set QUEUE_CONNECTION=database or redis and run: php artisan queue:work database-long --timeout=3700 --tries=1'
                    );
                }
                dispatch($job)->onConnection($async);
            } else {
                dispatch($job);
            }

            return;
        }

        // Batch mode (default 20 matches) completes in one chunk — run inline so refresh
        // does not depend on PHP terminating callbacks (often missing on artisan serve / Windows).
        if ($this->matchBatchSize() > 0) {
            try {
                $this->runFullRefreshUntilDone($warehouseId);
            } catch (Throwable $e) {
                report($e);
                $this->markRefreshFailed(
                    $warehouseId,
                    $e->getMessage() !== '' ? $e->getMessage() : 'Restock report refresh failed.'
                );
            }

            return;
        }

        $wid = $warehouseId;
        app()->terminating(function () use ($wid) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            try {
                $this->runFullRefreshUntilDone($wid);
            } catch (Throwable $e) {
                report($e);
                $this->markRefreshFailed(
                    $wid,
                    $e->getMessage() !== '' ? $e->getMessage() : 'Restock report refresh failed.'
                );
            }
        });
    }

    /**
     * @deprecated Use dispatchFullRefresh(); kept for any chained callers.
     */
    public function dispatchNextRefreshChunk(?string $warehouseId = null): void
    {
        $this->dispatchFullRefresh($warehouseId);
    }

    public function restockQueueConnection(): ?string
    {
        $preferred = trim((string) config('queue.restock_long_connection', 'database-long'));
        if ($preferred !== '' && config("queue.connections.{$preferred}.driver") !== null) {
            return $preferred;
        }

        return $this->asyncQueueConnection();
    }

    private function asyncQueueConnection(): ?string
    {
        foreach (['redis', 'database', 'beanstalkd', 'sqs'] as $name) {
            if (config("queue.connections.{$name}.driver") !== null) {
                return $name;
            }
        }

        return null;
    }

    public function markRefreshFailed(?string $warehouseId, string $message): void
    {
        $wid = $warehouseId !== null && trim($warehouseId) !== ''
            ? trim($warehouseId)
            : $this->resolveWarehouseId(null);

        $row = InventoryRestockSnapshot::query()
            ->where('warehouse_id', $wid)
            ->first();

        if ($row === null || $row->status !== InventoryRestockSnapshot::STATUS_RUNNING) {
            return;
        }

        $row->status = InventoryRestockSnapshot::STATUS_FAILED;
        $row->error_message = $message;
        $row->scan_cursor = null;
        $row->save();
    }

    public function touchRefreshProgress(string $warehouseId, int $page, int $partialRowCount, ?array $scanStats = null): void
    {
        $payload = [
            'progress_page' => max(0, $page),
            'row_count' => max(0, $partialRowCount),
            'updated_at' => Carbon::now(),
        ];
        if ($scanStats !== null) {
            $payload['scan_stats'] = $scanStats;
        }

        InventoryRestockSnapshot::query()
            ->where('warehouse_id', $warehouseId)
            ->where('status', InventoryRestockSnapshot::STATUS_RUNNING)
            ->update($payload);
    }

    public function configuredWarehouseId(?string $warehouseId = null): ?string
    {
        if ($warehouseId !== null && trim($warehouseId) !== '') {
            return trim($warehouseId);
        }

        $configured = trim((string) config('services.shiphero.restock_warehouse_id', ''));

        return $configured !== '' ? $configured : null;
    }

    public function resolveWarehouseId(?string $warehouseId = null): string
    {
        $configured = $this->configuredWarehouseId($warehouseId);
        if ($configured !== null) {
            return $configured;
        }

        return Cache::remember('inventory.restock.default_warehouse_id', now()->addHour(), function () {
            $warehouses = $this->inventory->listWarehouses();
            if ($warehouses === []) {
                throw new RuntimeException('No ShipHero warehouses available for restock report.');
            }

            return (string) ($warehouses[0]['id'] ?? '');
        });
    }

    private function findSnapshotRow(string $warehouseId, bool $withRows): ?InventoryRestockSnapshot
    {
        $columns = self::SNAPSHOT_META_COLUMNS;
        if ($withRows) {
            $columns[] = 'rows';
        }

        return InventoryRestockSnapshot::query()
            ->select($columns)
            ->where('warehouse_id', $warehouseId)
            ->first();
    }

    /**
     * Run a full refresh synchronously (artisan / tests). Chains chunks in-process.
     *
     * @return array<string, mixed>
     */
    public function refresh(?string $warehouseId = null): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $wid = $this->resolveWarehouseId($warehouseId);
        $row = InventoryRestockSnapshot::query()->where('warehouse_id', $wid)->first();
        if ($row === null || $row->status !== InventoryRestockSnapshot::STATUS_RUNNING) {
            $this->markRefreshRunning($warehouseId);
        }

        $result = ['has_more' => true];
        try {
            while ($result['has_more'] ?? false) {
                $result = $this->refreshNextChunk($warehouseId);
            }
        } finally {
            $this->clearRefreshWorkingState($wid);
        }

        $final = $this->findSnapshotRow($wid, true);
        if ($final === null) {
            throw new RuntimeException('Restock snapshot missing after refresh.');
        }

        return $this->serializeSnapshot($final, true);
    }

    /**
     * Scan ShipHero until the next batch of matches is found; append to snapshot.
     *
     * @return array<string, mixed>
     */
    public function loadMoreMatches(?string $warehouseId = null): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $wid = $this->resolveWarehouseId($warehouseId);
        $row = InventoryRestockSnapshot::query()->where('warehouse_id', $wid)->first();
        if ($row === null || $row->status !== InventoryRestockSnapshot::STATUS_OK) {
            throw new RuntimeException('No completed restock report to extend.');
        }

        if (! $this->snapshotHasMoreToScan($row)) {
            throw new RuntimeException('No more warehouse products to scan for restock matches.');
        }

        $existingRows = is_array($row->rows) ? $row->rows : [];
        $priorStats = is_array($row->scan_stats) ? $row->scan_stats : [];
        $startCursor = is_string($row->scan_cursor) && trim($row->scan_cursor) !== ''
            ? trim($row->scan_cursor)
            : null;

        $catalog = $this->buildLocationCatalogForRefresh($wid);
        $batchSize = max(1, $this->matchBatchSize() ?: 20);
        $scanResult = $this->scanWarehouse(
            $wid,
            $catalog['by_id'],
            $catalog['by_name'],
            null,
            null,
            $startCursor,
            (int) ($priorStats['pages_scanned'] ?? 0),
            (int) ($priorStats['products_scanned'] ?? 0),
            $existingRows,
            $batchSize
        );

        $rows = $this->dedupeRestockRows($scanResult['rows']);
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? '')));

        $scanStats = $scanResult['scan_stats'];
        $hasMoreToScan = (bool) ($scanStats['has_more_to_scan'] ?? false);
        $endCursor = $hasMoreToScan && isset($scanStats['end_cursor']) && is_string($scanStats['end_cursor'])
            ? $scanStats['end_cursor']
            : null;
        unset($scanStats['end_cursor']);

        $row->rows = $rows;
        $row->row_count = count($rows);
        $row->scan_stats = $scanStats;
        $row->scan_cursor = $endCursor;
        $row->save();

        return $this->serializeSnapshot($row, true);
    }

    /**
     * Process one paginated chunk of the restock scan and persist progress.
     *
     * @return array{has_more: bool, warehouse_id: string, row_count: int, scan_stats: ?array<string, mixed>}
     */
    public function refreshNextChunk(?string $warehouseId = null): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $wid = $this->resolveWarehouseId($warehouseId);
        $row = InventoryRestockSnapshot::query()->where('warehouse_id', $wid)->first();
        if ($row === null || $row->status !== InventoryRestockSnapshot::STATUS_RUNNING) {
            Log::warning('inventory.restock_report.chunk_skipped_not_running', [
                'warehouse_id' => $wid,
                'status' => $row !== null ? $row->status : null,
            ]);

            return [
                'has_more' => false,
                'warehouse_id' => $wid,
                'row_count' => $row !== null ? (int) $row->row_count : 0,
                'scan_stats' => is_array($row->scan_stats ?? null) ? $row->scan_stats : null,
            ];
        }

        $existingRows = $this->pendingRowsForWarehouse($wid, $row);
        $priorStats = is_array($row->scan_stats) ? $row->scan_stats : [];
        $cumulativePages = (int) ($priorStats['pages_scanned'] ?? $row->progress_page ?? 0);
        $cumulativeProducts = (int) ($priorStats['products_scanned'] ?? 0);
        $startCursor = is_string($row->scan_cursor) && trim($row->scan_cursor) !== ''
            ? trim($row->scan_cursor)
            : null;
        $refreshStartedAt = $row->refresh_started_at ?? Carbon::now();

        try {
            $catalog = $this->buildLocationCatalogForRefresh($wid);
            $batchSize = $this->matchBatchSize();
            $scanResult = $this->scanWarehouse(
                $wid,
                $catalog['by_id'],
                $catalog['by_name'],
                $batchSize > 0 ? null : $this->chunkPages(),
                null,
                $startCursor,
                $cumulativePages,
                $cumulativeProducts,
                $existingRows,
                $batchSize > 0 ? $batchSize : 0
            );

            $rows = $this->dedupeRestockRows($scanResult['rows']);
            $scanStats = $scanResult['scan_stats'];
            $unlimitedScan = $batchSize <= 0;
            $hasMoreToScan = (bool) ($scanStats['has_more_to_scan'] ?? false);
            $endCursor = $hasMoreToScan && isset($scanStats['end_cursor']) && is_string($scanStats['end_cursor'])
                ? $scanStats['end_cursor']
                : null;

            if ($unlimitedScan && $hasMoreToScan && $endCursor !== null && $endCursor !== '') {
                $this->setPendingRows($wid, $rows);
                $statsForDb = $scanStats;
                unset($statsForDb['end_cursor']);

                InventoryRestockSnapshot::query()
                    ->where('warehouse_id', $wid)
                    ->where('status', InventoryRestockSnapshot::STATUS_RUNNING)
                    ->update([
                        'rows' => [],
                        'row_count' => count($rows),
                        'progress_page' => (int) ($scanStats['pages_scanned'] ?? 0),
                        'scan_cursor' => $endCursor,
                        'scan_stats' => $statsForDb,
                        'updated_at' => Carbon::now(),
                    ]);

                return [
                    'has_more' => true,
                    'warehouse_id' => $wid,
                    'row_count' => count($rows),
                    'scan_stats' => $scanStats,
                ];
            }

            usort($rows, static fn (array $a, array $b): int => strcasecmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? '')));
            $durationMs = (int) max(0, $refreshStartedAt->diffInRealSeconds(Carbon::now()) * 1000);
            $computedAt = Carbon::now();
            unset($scanStats['end_cursor']);

            $this->clearRefreshWorkingState($wid);

            InventoryRestockSnapshot::query()->updateOrCreate(
                ['warehouse_id' => $wid],
                [
                    'computed_at' => $computedAt,
                    'rows' => $rows,
                    'row_count' => count($rows),
                    'status' => InventoryRestockSnapshot::STATUS_OK,
                    'error_message' => null,
                    'duration_ms' => $durationMs,
                    'refresh_started_at' => null,
                    'progress_page' => null,
                    'scan_cursor' => $endCursor,
                    'scan_stats' => $scanStats,
                ]
            );

            return [
                'has_more' => false,
                'warehouse_id' => $wid,
                'row_count' => count($rows),
                'scan_stats' => $scanStats,
            ];
        } catch (Throwable $e) {
            Log::error('inventory.restock_report.chunk_failed', [
                'warehouse_id' => $wid,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Partial scan for a quick match count (does not write snapshot).
     *
     * @return array<string, mixed>
     */
    public function preview(?string $warehouseId = null, ?int $maxPages = null, ?int $maxPickableQty = null): array
    {
        $wid = $this->resolveWarehouseId($warehouseId);
        $pageLimit = max(1, min(100, $maxPages ?? 10));
        $qtyLimit = $maxPickableQty ?? $this->maxPickableQty();
        $catalog = $this->buildLocationCatalogForRefresh($wid);
        $scanResult = $this->scanWarehouse(
            $wid,
            $catalog['by_id'],
            $catalog['by_name'],
            $pageLimit,
            $qtyLimit
        );

        $rows = $scanResult['rows'];
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? '')));

        $stats = $scanResult['scan_stats'];
        $stats['partial'] = ($stats['pages_scanned'] ?? 0) >= $pageLimit && ($stats['has_more_pages'] ?? false);

        return [
            'warehouse_id' => $wid,
            'match_count' => count($rows),
            'products_scanned' => (int) ($stats['products_scanned'] ?? 0),
            'pages_scanned' => (int) ($stats['pages_scanned'] ?? 0),
            'max_pickable_qty' => $qtyLimit,
            'partial' => (bool) ($stats['partial'] ?? false),
            'sample_rows' => array_slice($rows, 0, 5),
            'scan_stats' => $stats,
        ];
    }

    /**
     * @return array{by_id: array<string, array<string, mixed>>, by_name: array<string, array<string, mixed>>}
     */
    private function buildLocationCatalogForRefresh(string $warehouseId): array
    {
        if (isset($this->refreshCatalogCache[$warehouseId])) {
            return $this->refreshCatalogCache[$warehouseId];
        }

        if (filter_var(config('services.shiphero.restock_skip_location_catalog'), FILTER_VALIDATE_BOOLEAN)) {
            $empty = ['by_id' => [], 'by_name' => []];
            $this->refreshCatalogCache[$warehouseId] = $empty;

            return $empty;
        }

        try {
            $catalog = $this->inventory->buildWarehouseLocationPickableCatalog($warehouseId);
        } catch (Throwable $e) {
            Log::warning('inventory.restock_report.location_catalog_skipped', [
                'warehouse_id' => $warehouseId,
                'message' => $e->getMessage(),
            ]);
            $catalog = ['by_id' => [], 'by_name' => []];
        }

        $this->refreshCatalogCache[$warehouseId] = $catalog;

        return $catalog;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingRowsForWarehouse(string $warehouseId, InventoryRestockSnapshot $row): array
    {
        if (isset($this->pendingRefreshRowsByWarehouse[$warehouseId])) {
            return $this->pendingRefreshRowsByWarehouse[$warehouseId];
        }

        if ($row->status === InventoryRestockSnapshot::STATUS_RUNNING) {
            return [];
        }

        return is_array($row->rows) ? $row->rows : [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function setPendingRows(string $warehouseId, array $rows): void
    {
        $this->pendingRefreshRowsByWarehouse[$warehouseId] = $rows;
    }

    private function clearRefreshWorkingState(?string $warehouseId = null): void
    {
        if ($warehouseId !== null && $warehouseId !== '') {
            unset($this->pendingRefreshRowsByWarehouse[$warehouseId], $this->refreshCatalogCache[$warehouseId]);

            return;
        }

        $this->pendingRefreshRowsByWarehouse = [];
        $this->refreshCatalogCache = [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestSnapshot(?string $warehouseId = null, bool $includeRows = false): ?array
    {
        $wid = $this->configuredWarehouseId($warehouseId) ?? $this->resolveWarehouseId($warehouseId);
        $loadRowsColumn = $includeRows;
        $row = $this->findSnapshotRow($wid, $loadRowsColumn);

        if ($row === null) {
            return null;
        }

        $row = $this->resolveStaleRunningSnapshot($row);

        $returnRows = $includeRows && $row->status !== InventoryRestockSnapshot::STATUS_RUNNING;

        return $this->serializeSnapshot($row, $returnRows);
    }

    public function resolveWarehouseIdForApi(?string $warehouseId = null): string
    {
        $configured = $this->configuredWarehouseId($warehouseId);
        if ($configured !== null) {
            return $configured;
        }

        return $this->resolveWarehouseId($warehouseId);
    }

    public function resolveStaleRunningSnapshot(InventoryRestockSnapshot $row): InventoryRestockSnapshot
    {
        if ($row->status !== InventoryRestockSnapshot::STATUS_RUNNING) {
            return $row;
        }

        if ($this->isZombieRunningSnapshot($row)) {
            $row->status = InventoryRestockSnapshot::STATUS_FAILED;
            $row->error_message = 'Refresh never started (background worker did not run). Click Refresh to scan again.';
            $row->refresh_started_at = null;
            $row->progress_page = null;
            $row->scan_cursor = null;
            $row->save();

            return $row;
        }

        $lastTouch = $row->updated_at ?? $row->refresh_started_at;
        if ($lastTouch !== null && $lastTouch->lessThan(now()->subMinutes($this->stallMinutes()))) {
            $row->status = InventoryRestockSnapshot::STATUS_FAILED;
            $row->error_message = 'Refresh stalled (no progress). Click Refresh to retry. If this keeps happening, set SHIPHERO_RESTOCK_DISPATCH_MODE=queue and run: php artisan queue:work database-long --timeout=3700 --tries=1';
            $row->refresh_started_at = null;
            $row->progress_page = null;
            $row->scan_cursor = null;
            $row->save();

            return $row;
        }

        $startedAt = $row->refresh_started_at ?? $row->updated_at;
        if ($startedAt === null) {
            return $row;
        }

        if ($startedAt->greaterThan(now()->subMinutes($this->staleMinutes()))) {
            return $row;
        }

        $row->status = InventoryRestockSnapshot::STATUS_FAILED;
        $row->error_message = 'Refresh did not finish in time. Click Refresh to retry or use queue mode with a worker.';
        $row->refresh_started_at = null;
        $row->progress_page = null;
        $row->scan_cursor = null;
        $row->save();

        return $row;
    }

    /**
     * @param  array<string, array<string, mixed>>  $catalogById
     * @param  array<string, array<string, mixed>>  $catalogByName
     * @param  list<array<string, mixed>>  $existingRows
     * @return array{rows: list<array<string, mixed>>, scan_stats: array<string, mixed>}
     */
    private function snapshotHasMoreToScan(InventoryRestockSnapshot $row): bool
    {
        if (is_string($row->scan_cursor) && trim($row->scan_cursor) !== '') {
            return true;
        }
        $stats = is_array($row->scan_stats) ? $row->scan_stats : [];

        return (bool) ($stats['has_more_to_scan'] ?? $stats['has_more_pages'] ?? false);
    }

    /**
     * Running row with no scan progress — background refresh likely never executed.
     */
    private function isZombieRunningSnapshot(InventoryRestockSnapshot $row): bool
    {
        if ($row->status !== InventoryRestockSnapshot::STATUS_RUNNING) {
            return false;
        }

        $started = $row->refresh_started_at ?? $row->created_at;
        if ($started === null || $started->greaterThan(now()->subMinutes(2))) {
            return false;
        }

        if ((int) ($row->progress_page ?? 0) > 0) {
            return false;
        }

        $stats = is_array($row->scan_stats) ? $row->scan_stats : [];
        if ((int) ($stats['products_scanned'] ?? 0) > 0) {
            return false;
        }

        $updated = $row->updated_at;
        if ($updated !== null && $started !== null && $updated->greaterThan($started->copy()->addSeconds(30))) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function dedupeRestockRows(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '' || isset($seen[$sku])) {
                continue;
            }
            $seen[$sku] = true;
            $out[] = $row;
        }

        return $out;
    }

    private function scanWarehouse(
        string $warehouseId,
        array $catalogById,
        array $catalogByName,
        ?int $maxPagesOverride = null,
        ?int $maxPickableQtyOverride = null,
        ?string $startCursor = null,
        int $cumulativePages = 0,
        int $cumulativeProductsScanned = 0,
        array $existingRows = [],
        int $matchBatchSize = 0
    ): array {
        $restockRows = $existingRows;
        $matchesAtStart = count($existingRows);
        $after = $startCursor;
        $chunkPageLimit = $maxPagesOverride ?? 500;
        $safetyPageLimit = $matchBatchSize > 0 ? $this->scanSafetyMaxPages() : $chunkPageLimit;
        $maxPickableQty = $maxPickableQtyOverride ?? $this->maxPickableQty();
        $page = $cumulativePages;
        $productsScanned = $cumulativeProductsScanned;
        $hasNext = false;
        $pagesThisChunk = 0;
        $stoppedAtMatchBatch = false;

        do {
            $pageResult = $this->inventory->paginateWarehouseProductsForRestock($warehouseId, $after);
            $edges = is_array($pageResult['edges'] ?? null) ? $pageResult['edges'] : [];
            foreach ($edges as $edge) {
                $wp = is_array($edge['node'] ?? null) ? $edge['node'] : null;
                if ($wp === null) {
                    continue;
                }
                if (($wp['active'] ?? null) === false) {
                    continue;
                }

                $product = is_array($wp['product'] ?? null) ? $wp['product'] : null;
                if ($product === null) {
                    continue;
                }
                if (($product['active'] ?? null) === false) {
                    continue;
                }
                $isKit = ($product['kit'] ?? false) === true || ($product['kit_build'] ?? false) === true;
                if ($isKit) {
                    continue;
                }

                $sku = trim((string) ($product['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }

                $productsScanned++;

                $locations = $this->inventory->enrichedLocationsForWarehouseProduct(
                    $wp,
                    $warehouseId,
                    $catalogById,
                    $catalogByName,
                    false
                );

                $built = InventoryRestockRowBuilder::buildRow(
                    $sku,
                    (string) ($product['name'] ?? ''),
                    $this->inventory->primaryProductImageUrl($product),
                    $locations,
                    $maxPickableQty
                );
                if ($built !== null) {
                    $restockRows[] = $built;
                }
            }

            $pageInfo = is_array($pageResult['page_info'] ?? null) ? $pageResult['page_info'] : [];
            $hasNext = (bool) ($pageInfo['has_next_page'] ?? false);
            $after = isset($pageInfo['end_cursor']) && is_string($pageInfo['end_cursor']) ? $pageInfo['end_cursor'] : null;
            $page++;
            $pagesThisChunk++;

            if ($maxPagesOverride === null && ($page === 1 || $page % 3 === 0)) {
                $partialStats = [
                    'products_scanned' => $productsScanned,
                    'products_matched' => count($restockRows),
                    'pages_scanned' => $page,
                    'max_pickable_qty' => $maxPickableQty,
                    'has_more_pages' => $hasNext,
                ];
                $this->touchRefreshProgress($warehouseId, $page, count($restockRows), $partialStats);
            } elseif ($maxPagesOverride !== null && ($pagesThisChunk === 1 || $pagesThisChunk % 2 === 0)) {
                $partialStats = [
                    'products_scanned' => $productsScanned,
                    'products_matched' => count($restockRows),
                    'pages_scanned' => $page,
                    'max_pickable_qty' => $maxPickableQty,
                    'has_more_pages' => $hasNext,
                    'end_cursor' => $after,
                ];
                $this->touchRefreshProgress($warehouseId, $page, count($restockRows), $partialStats);
            }

            if ($matchBatchSize > 0 && (count($restockRows) - $matchesAtStart) >= $matchBatchSize) {
                $stoppedAtMatchBatch = $hasNext;
                break;
            }
            if ($matchBatchSize > 0 && $pagesThisChunk >= $safetyPageLimit) {
                $stoppedAtMatchBatch = $hasNext;
                break;
            }
        } while ($hasNext && $after !== null && $after !== '' && $pagesThisChunk < $chunkPageLimit);

        $hasMoreToScan = $hasNext && ($stoppedAtMatchBatch || $matchBatchSize > 0);
        if ($stoppedAtMatchBatch && $matchBatchSize > 0) {
            Log::info('inventory.restock_report.match_batch_ready', [
                'warehouse_id' => $warehouseId,
                'match_batch_size' => $matchBatchSize,
                'matches_in_batch' => count($restockRows) - $matchesAtStart,
                'products_matched_total' => count($restockRows),
            ]);
        }

        $scanStats = [
            'products_scanned' => $productsScanned,
            'products_matched' => count($restockRows),
            'pages_scanned' => $page,
            'max_pickable_qty' => $maxPickableQty,
            'match_batch_size' => $matchBatchSize > 0 ? $matchBatchSize : null,
            'matches_in_batch' => $matchBatchSize > 0 ? count($restockRows) - $matchesAtStart : null,
            'has_more_to_scan' => $hasMoreToScan,
            'has_more_pages' => $hasMoreToScan,
        ];
        if ($hasNext && $after !== null && $after !== '') {
            $scanStats['end_cursor'] = $after;
        }

        return [
            'rows' => $restockRows,
            'scan_stats' => $scanStats,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSnapshot(InventoryRestockSnapshot $row, bool $includeRows = true): array
    {
        $isRunning = $row->status === InventoryRestockSnapshot::STATUS_RUNNING;
        $computedAt = $isRunning ? null : $row->computed_at;
        $refreshStartedAt = $row->refresh_started_at;
        $rows = [];
        $totalRows = (int) $row->row_count;
        $hasMoreToScan = false;

        if ($includeRows && is_array($row->rows)) {
            $rows = $row->rows;
            $totalRows = max($totalRows, count($rows));
        }

        if (! $isRunning) {
            $hasMoreToScan = $this->snapshotHasMoreToScan($row);
        }

        return [
            'warehouse_id' => $row->warehouse_id,
            'computed_at' => $computedAt !== null ? $computedAt->toIso8601String() : null,
            'rows' => $rows,
            'row_count' => $totalRows,
            'status' => (string) $row->status,
            'error_message' => $row->error_message,
            'duration_ms' => $isRunning ? null : $row->duration_ms,
            'refresh_started_at' => $refreshStartedAt !== null ? $refreshStartedAt->toIso8601String() : null,
            'progress_page' => $isRunning ? $row->progress_page : null,
            'scan_stats' => is_array($row->scan_stats) ? $row->scan_stats : null,
            'has_more_to_scan' => $hasMoreToScan,
            'has_more_rows' => $hasMoreToScan,
        ];
    }
}
