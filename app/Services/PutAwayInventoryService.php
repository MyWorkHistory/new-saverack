<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\PutAwaySnapshot;
use App\Models\PutAwaySnapshotRow;
use App\Models\ShipHeroInventoryProductIndex;
use App\Support\PutAwayRowBuilder;
use RuntimeException;
use Throwable;

class PutAwayInventoryService
{
    public const CACHE_TTL_MINUTES = 30;

    /** @var ShipHeroInventoryService */
    protected $inventory;

    /** @var InventoryRestockReportService */
    protected $restockReports;

    public function __construct(
        ShipHeroInventoryService $inventory,
        InventoryRestockReportService $restockReports
    ) {
        $this->inventory = $inventory;
        $this->restockReports = $restockReports;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, page_info: array{has_next_page: bool, end_cursor: string|null}, meta: array<string, mixed>}
     */
    public function list(
        int $clientAccountId,
        ?string $query,
        int $first,
        ?string $after,
        bool $refresh = false
    ): array {
        $account = ClientAccount::query()->findOrFail($clientAccountId);
        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            throw new RuntimeException('This account is not linked to ShipHero.');
        }

        $snapshot = PutAwaySnapshot::query()->where('client_account_id', $clientAccountId)->first();
        if ($refresh || $snapshot === null) {
            $snapshot = $this->rebuildSnapshot($clientAccountId, $account, $customerId);
        }

        return $this->paginateSnapshotRows($snapshot, $query, $first, $after);
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

        return $this->paginateSnapshotRows($snapshot, null, 50, null);
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
    private function scanWarehouseLocationsForSkus(string $warehouseId, array $allowList): array
    {
        if ($allowList === []) {
            return [];
        }

        $catalog = $this->inventory->buildWarehouseLocationPickableCatalog($warehouseId);
        $catalogById = is_array($catalog['by_id'] ?? null) ? $catalog['by_id'] : [];
        $catalogByName = is_array($catalog['by_name'] ?? null) ? $catalog['by_name'] : [];

        $out = [];
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
                if (! isset($allowList[$skuKey])) {
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
}
