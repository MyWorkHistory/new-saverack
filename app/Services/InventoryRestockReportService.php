<?php

namespace App\Services;

use App\Models\InventoryRestockSnapshot;
use App\Support\InventoryRestockRowBuilder;
use Illuminate\Support\Carbon;
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

    public function isRefreshInProgress(?string $warehouseId = null): bool
    {
        $wid = $this->resolveWarehouseId($warehouseId);
        $row = InventoryRestockSnapshot::query()
            ->where('warehouse_id', $wid)
            ->first();

        if ($row === null || $row->status !== InventoryRestockSnapshot::STATUS_RUNNING) {
            return false;
        }

        return $row->updated_at !== null && $row->updated_at->greaterThan(now()->subMinutes(30));
    }

    /**
     * @return array<string, mixed>
     */
    public function markRefreshRunning(?string $warehouseId = null): array
    {
        $wid = $this->resolveWarehouseId($warehouseId);

        $row = InventoryRestockSnapshot::query()->updateOrCreate(
            ['warehouse_id' => $wid],
            [
                'status' => InventoryRestockSnapshot::STATUS_RUNNING,
                'error_message' => null,
            ]
        );

        return $this->serializeSnapshot($row);
    }

    public function resolveWarehouseId(?string $warehouseId = null): string
    {
        $configured = trim((string) config('services.shiphero.restock_warehouse_id', ''));
        if ($warehouseId !== null && trim($warehouseId) !== '') {
            return trim($warehouseId);
        }
        if ($configured !== '') {
            return $configured;
        }

        $warehouses = $this->inventory->listWarehouses();
        if ($warehouses === []) {
            throw new RuntimeException('No ShipHero warehouses available for restock report.');
        }

        return (string) ($warehouses[0]['id'] ?? '');
    }

    /**
     * @return array{warehouse_id: string, computed_at: string, rows: list<array<string, mixed>>, row_count: int, status: string, error_message: ?string, duration_ms: int}
     */
    public function refresh(?string $warehouseId = null): array
    {
        $started = microtime(true);
        $wid = $this->resolveWarehouseId($warehouseId);
        $status = InventoryRestockSnapshot::STATUS_OK;
        $errorMessage = null;
        $rows = [];

        try {
            $catalog = $this->inventory->buildWarehouseLocationPickableCatalog($wid);
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
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestSnapshot(?string $warehouseId = null): ?array
    {
        $wid = $this->resolveWarehouseId($warehouseId);
        $row = InventoryRestockSnapshot::query()
            ->where('warehouse_id', $wid)
            ->first();

        if ($row === null) {
            return null;
        }

        // Guard against stale "running" rows when a queue worker died mid-run.
        if (
            $row->status === InventoryRestockSnapshot::STATUS_RUNNING
            && $row->updated_at !== null
            && $row->updated_at->lessThan(now()->subMinutes(30))
        ) {
            $row->status = InventoryRestockSnapshot::STATUS_FAILED;
            $row->error_message = 'Restock refresh timed out. Please refresh again.';
            $row->save();
        }

        return $this->serializeSnapshot($row);
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
    private function serializeSnapshot(InventoryRestockSnapshot $row): array
    {
        $computedAt = $row->computed_at;

        return [
            'warehouse_id' => $row->warehouse_id,
            'computed_at' => $computedAt !== null ? $computedAt->toIso8601String() : null,
            'rows' => is_array($row->rows) ? $row->rows : [],
            'row_count' => (int) $row->row_count,
            'status' => (string) $row->status,
            'error_message' => $row->error_message,
            'duration_ms' => $row->duration_ms,
        ];
    }
}
