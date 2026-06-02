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
     * Run restock refresh after the HTTP response is sent (no separate queue worker required).
     */
    public function dispatchRefreshJob(?string $warehouseId = null): void
    {
        $this->dispatchNextRefreshChunk($warehouseId);
    }

    /**
     * Dispatch one refresh chunk (initial or continuation).
     */
    public function dispatchNextRefreshChunk(?string $warehouseId = null): void
    {
        $mode = strtolower(trim((string) config('services.shiphero.restock_dispatch_mode', 'after_response')));

        if ($mode === 'queue') {
            $job = new RefreshInventoryRestockReportJob($warehouseId);
            $default = (string) config('queue.default', 'sync');
            if ($default === 'sync') {
                $async = $this->restockQueueConnection();
                if ($async === null) {
                    throw new RuntimeException(
                        'Restock refresh requires a background queue. Set QUEUE_CONNECTION=database or redis and run php artisan queue:work --timeout=700.'
                    );
                }
                dispatch($job)->onConnection($async);
            } else {
                dispatch($job);
            }

            return;
        }

        RefreshInventoryRestockReportJob::dispatch($warehouseId)->afterResponse();
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
        while ($result['has_more'] ?? false) {
            $result = $this->refreshNextChunk($warehouseId);
        }

        $final = $this->findSnapshotRow($wid, true);
        if ($final === null) {
            throw new RuntimeException('Restock snapshot missing after refresh.');
        }

        return $this->serializeSnapshot($final, true);
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
            return [
                'has_more' => false,
                'warehouse_id' => $wid,
                'row_count' => 0,
                'scan_stats' => null,
            ];
        }

        $existingRows = is_array($row->rows) ? $row->rows : [];
        $priorStats = is_array($row->scan_stats) ? $row->scan_stats : [];
        $cumulativePages = (int) ($priorStats['pages_scanned'] ?? $row->progress_page ?? 0);
        $cumulativeProducts = (int) ($priorStats['products_scanned'] ?? 0);
        $startCursor = is_string($row->scan_cursor) && trim($row->scan_cursor) !== ''
            ? trim($row->scan_cursor)
            : null;
        $refreshStartedAt = $row->refresh_started_at ?? Carbon::now();

        try {
            $catalog = $this->buildLocationCatalogForRefresh($wid);
            $scanResult = $this->scanWarehouse(
                $wid,
                $catalog['by_id'],
                $catalog['by_name'],
                $this->chunkPages(),
                null,
                $startCursor,
                $cumulativePages,
                $cumulativeProducts,
                $existingRows
            );

            $rows = $scanResult['rows'];
            $scanStats = $scanResult['scan_stats'];
            $hasMore = (bool) ($scanStats['has_more_pages'] ?? false);
            $endCursor = isset($scanStats['end_cursor']) && is_string($scanStats['end_cursor'])
                ? $scanStats['end_cursor']
                : null;

            if ($hasMore && $endCursor !== null && $endCursor !== '') {
                InventoryRestockSnapshot::query()
                    ->where('warehouse_id', $wid)
                    ->where('status', InventoryRestockSnapshot::STATUS_RUNNING)
                    ->update([
                        'rows' => $rows,
                        'row_count' => count($rows),
                        'progress_page' => (int) ($scanStats['pages_scanned'] ?? 0),
                        'scan_cursor' => $endCursor,
                        'scan_stats' => $scanStats,
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
            $scanStats['has_more_pages'] = false;
            unset($scanStats['end_cursor']);

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
                    'scan_cursor' => null,
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
        if (filter_var(config('services.shiphero.restock_skip_location_catalog'), FILTER_VALIDATE_BOOLEAN)) {
            return ['by_id' => [], 'by_name' => []];
        }

        try {
            return $this->inventory->buildWarehouseLocationPickableCatalog($warehouseId);
        } catch (Throwable $e) {
            Log::warning('inventory.restock_report.location_catalog_skipped', [
                'warehouse_id' => $warehouseId,
                'message' => $e->getMessage(),
            ]);

            return ['by_id' => [], 'by_name' => []];
        }
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

        $startedAt = $row->refresh_started_at ?? $row->updated_at;
        if ($startedAt === null) {
            return $row;
        }

        if ($startedAt->greaterThan(now()->subMinutes($this->staleMinutes()))) {
            return $row;
        }

        $row->status = InventoryRestockSnapshot::STATUS_FAILED;
        $row->error_message = 'Refresh did not finish (queue worker may be stopped). Try Refresh again or contact ops.';
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
    private function scanWarehouse(
        string $warehouseId,
        array $catalogById,
        array $catalogByName,
        ?int $maxPagesOverride = null,
        ?int $maxPickableQtyOverride = null,
        ?string $startCursor = null,
        int $cumulativePages = 0,
        int $cumulativeProductsScanned = 0,
        array $existingRows = []
    ): array {
        $restockRows = $existingRows;
        $after = $startCursor;
        $maxPages = $maxPagesOverride ?? 500;
        $maxPickableQty = $maxPickableQtyOverride ?? $this->maxPickableQty();
        $page = $cumulativePages;
        $productsScanned = $cumulativeProductsScanned;
        $hasNext = false;
        $pagesThisChunk = 0;

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
                    null,
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
            } elseif ($maxPagesOverride !== null && ($pagesThisChunk === 1 || $pagesThisChunk % 3 === 0)) {
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
        } while ($hasNext && $after !== null && $after !== '' && $pagesThisChunk < $maxPages);

        if ($page >= 500 && $hasNext && $maxPagesOverride === null) {
            Log::warning('inventory.restock_report.pagination_truncated', [
                'warehouse_id' => $warehouseId,
                'max_pages' => 500,
            ]);
        }

        $scanStats = [
            'products_scanned' => $productsScanned,
            'products_matched' => count($restockRows),
            'pages_scanned' => $page,
            'max_pickable_qty' => $maxPickableQty,
            'has_more_pages' => $hasNext,
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
        if ($includeRows && is_array($row->rows)) {
            $rows = $row->rows;
            $max = max(100, (int) config('services.shiphero.restock_api_max_rows', 5000));
            if (count($rows) > $max) {
                $rows = array_slice($rows, 0, $max);
            }
        }

        return [
            'warehouse_id' => $row->warehouse_id,
            'computed_at' => $computedAt !== null ? $computedAt->toIso8601String() : null,
            'rows' => $rows,
            'row_count' => (int) $row->row_count,
            'status' => (string) $row->status,
            'error_message' => $row->error_message,
            'duration_ms' => $isRunning ? null : $row->duration_ms,
            'refresh_started_at' => $refreshStartedAt !== null ? $refreshStartedAt->toIso8601String() : null,
            'progress_page' => $isRunning ? $row->progress_page : null,
            'scan_stats' => is_array($row->scan_stats) ? $row->scan_stats : null,
        ];
    }
}
