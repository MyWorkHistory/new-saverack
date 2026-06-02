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
        'created_at',
        'updated_at',
    ];

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
                'computed_at' => null,
                'duration_ms' => null,
                'rows' => [],
                'row_count' => 0,
            ]
        );

        return $this->serializeSnapshot($row, false);
    }

    /**
     * Queue restock refresh without blocking the HTTP request (avoids Cloudflare 502/524).
     */
    public function dispatchRefreshJob(?string $warehouseId = null): void
    {
        $job = new RefreshInventoryRestockReportJob($warehouseId);
        $default = (string) config('queue.default', 'sync');
        if ($default === 'sync') {
            $async = $this->asyncQueueConnection();
            if ($async === null) {
                throw new RuntimeException(
                    'Restock refresh requires a background queue. Set QUEUE_CONNECTION=database or redis and run php artisan queue:work.'
                );
            }
            dispatch($job)->onConnection($async);

            return;
        }

        dispatch($job);
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
        $row->save();
    }

    public function touchRefreshProgress(string $warehouseId, int $page, int $partialRowCount): void
    {
        InventoryRestockSnapshot::query()
            ->where('warehouse_id', $warehouseId)
            ->where('status', InventoryRestockSnapshot::STATUS_RUNNING)
            ->update([
                'progress_page' => max(0, $page),
                'row_count' => max(0, $partialRowCount),
                'updated_at' => Carbon::now(),
            ]);
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
     * @return array{warehouse_id: string, computed_at: string, rows: list<array<string, mixed>>, row_count: int, status: string, error_message: ?string, duration_ms: int, refresh_started_at: ?string, progress_page: ?int}
     */
    public function refresh(?string $warehouseId = null): array
    {
        $started = microtime(true);
        $wid = $this->resolveWarehouseId($warehouseId);
        $status = InventoryRestockSnapshot::STATUS_OK;
        $errorMessage = null;
        $rows = [];

        try {
            $catalog = $this->buildLocationCatalogForRefresh($wid);
            $rows = $this->scanWarehouse($wid, $catalog['by_id'], $catalog['by_name']);
        } catch (Throwable $e) {
            $status = InventoryRestockSnapshot::STATUS_FAILED;
            $errorMessage = $e->getMessage();
            Log::error('inventory.restock_report.failed', [
                'warehouse_id' => $wid,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $computedAt = Carbon::now();

            InventoryRestockSnapshot::query()->updateOrCreate(
                ['warehouse_id' => $wid],
                [
                    'computed_at' => $computedAt,
                    'rows' => $rows,
                    'row_count' => count($rows),
                    'status' => $status,
                    'error_message' => $errorMessage,
                    'duration_ms' => $durationMs,
                    'refresh_started_at' => null,
                    'progress_page' => null,
                ]
            );
        }

        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? '')));

        return [
            'warehouse_id' => $wid,
            'computed_at' => $computedAt->toIso8601String(),
            'rows' => $rows,
            'row_count' => count($rows),
            'status' => $status,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'refresh_started_at' => null,
            'progress_page' => null,
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
        $row->save();

        return $row;
    }

    /**
     * @param  array<string, array<string, mixed>>  $catalogById
     * @param  array<string, array<string, mixed>>  $catalogByName
     * @return list<array<string, mixed>>
     */
    private function scanWarehouse(string $warehouseId, array $catalogById, array $catalogByName): array
    {
        $restockRows = [];
        $after = null;
        $maxPages = 500;
        $page = 0;

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

                $locations = $this->inventory->enrichedLocationsForWarehouseProduct(
                    $wp,
                    $warehouseId,
                    $catalogById,
                    $catalogByName
                );

                $replenishmentMinimum = max(0, (int) ($wp['replenishment_level'] ?? 0));

                $built = InventoryRestockRowBuilder::buildRow(
                    $sku,
                    (string) ($product['name'] ?? ''),
                    null,
                    $locations,
                    $replenishmentMinimum
                );
                if ($built !== null) {
                    $restockRows[] = $built;
                }
            }

            $pageInfo = is_array($pageResult['page_info'] ?? null) ? $pageResult['page_info'] : [];
            $hasNext = (bool) ($pageInfo['has_next_page'] ?? false);
            $after = isset($pageInfo['end_cursor']) && is_string($pageInfo['end_cursor']) ? $pageInfo['end_cursor'] : null;
            $page++;

            if ($page % 10 === 0) {
                $this->touchRefreshProgress($warehouseId, $page, count($restockRows));
            }
        } while ($hasNext && $after !== null && $after !== '' && $page < $maxPages);

        if ($page >= $maxPages && $hasNext) {
            Log::warning('inventory.restock_report.pagination_truncated', [
                'warehouse_id' => $warehouseId,
                'max_pages' => $maxPages,
            ]);
        }

        return $restockRows;
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
        ];
    }
}
