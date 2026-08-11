<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\ClientAccountReturnLine;
use App\Models\ReturnBin;
use App\Models\ShipHeroInventoryProductIndex;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ReturnBinLocationSyncService
{
    /** @var ShipHeroInventoryService */
    private $inventory;

    /** @var InventoryRestockReportService */
    private $restockReports;

    public function __construct(ShipHeroInventoryService $inventory, InventoryRestockReportService $restockReports)
    {
        $this->inventory = $inventory;
        $this->restockReports = $restockReports;
    }

    /**
     * @return array{
     *   data: list<array<string, mixed>>,
     *   source: string,
     *   synced_at: ?string,
     *   warehouse_id: ?string,
     *   location_id: ?string,
     *   needs_sync: bool
     * }
     */
    public function listForDisplay(ReturnBin $bin, callable $crmListFallback): array
    {
        $cached = $this->getCachedSnapshot($bin);
        if (is_array($cached) && isset($cached['items']) && is_array($cached['items'])) {
            return [
                'data' => array_values($cached['items']),
                'source' => 'shiphero',
                'synced_at' => isset($cached['synced_at']) ? (string) $cached['synced_at'] : null,
                'warehouse_id' => isset($cached['warehouse_id']) ? (string) $cached['warehouse_id'] : null,
                'location_id' => isset($cached['location_id']) ? (string) $cached['location_id'] : null,
                'needs_sync' => false,
            ];
        }

        return [
            'data' => $crmListFallback($bin),
            'source' => 'crm',
            'synced_at' => null,
            'warehouse_id' => null,
            'location_id' => null,
            'needs_sync' => true,
        ];
    }

    /**
     * @return array{
     *   data: list<array<string, mixed>>,
     *   source: string,
     *   synced_at: string,
     *   warehouse_id: string,
     *   location_id: ?string,
     *   needs_sync: bool
     * }
     */
    public function syncFromShipHero(ReturnBin $bin): array
    {
        $warehouseId = $this->resolveReturnsWarehouseId();
        $locationName = trim((string) $bin->name);
        if ($locationName === '') {
            throw new RuntimeException('Return bin name is required for ShipHero sync.');
        }

        $built = [];
        $locationId = null;
        $after = null;
        $pageGuard = 0;

        do {
            $pageGuard++;
            if ($pageGuard > 200) {
                throw new RuntimeException('Return bin location sync exceeded safety page limit.');
            }

            $page = $this->inventory->paginateItemLocationsAtLocationName(
                $warehouseId,
                $locationName,
                $after,
                100
            );
            $edges = is_array($page['edges'] ?? null) ? $page['edges'] : [];
            foreach ($edges as $edge) {
                if (! is_array($edge)) {
                    continue;
                }
                $node = is_array($edge['node'] ?? null) ? $edge['node'] : null;
                if ($node === null) {
                    continue;
                }
                $sku = trim((string) ($node['sku'] ?? ''));
                $qty = max(0, (int) ($node['quantity'] ?? 0));
                if ($sku === '' || $qty <= 0) {
                    continue;
                }

                $locName = strtolower(trim((string) data_get($node, 'location.name', '')));
                if ($locName !== '' && $locName !== strtolower($locationName)) {
                    continue;
                }

                $nodeLocationId = trim((string) ($node['location_id'] ?? data_get($node, 'location.id', '')));
                if ($locationId === null && $nodeLocationId !== '') {
                    $locationId = $nodeLocationId;
                }

                $meta = $this->localProductMetaForSku($sku);
                $clientAccountId = (int) ($meta['client_account_id'] ?? 0);
                $key = ($clientAccountId > 0 ? $clientAccountId : 0).'|'.mb_strtolower($sku);
                if (! isset($built[$key])) {
                    $built[$key] = [
                        'sku' => $sku,
                        'name' => $meta['name'] ?? $sku,
                        'image_url' => $meta['image_url'] ?? null,
                        'qty' => $qty,
                        'client_account_id' => $clientAccountId,
                        'client_account_company_name' => '',
                        'pick_location' => '—',
                        'warehouse_id' => $warehouseId,
                        'from_location_id' => $nodeLocationId !== '' ? $nodeLocationId : $locationId,
                    ];
                } else {
                    $built[$key]['qty'] += $qty;
                    if (empty($built[$key]['from_location_id']) && $nodeLocationId !== '') {
                        $built[$key]['from_location_id'] = $nodeLocationId;
                    }
                }
            }

            $pageInfo = is_array($page['page_info'] ?? null) ? $page['page_info'] : [];
            $hasNext = (bool) ($pageInfo['has_next_page'] ?? false);
            $after = isset($pageInfo['end_cursor']) && is_string($pageInfo['end_cursor']) && $pageInfo['end_cursor'] !== ''
                ? $pageInfo['end_cursor']
                : null;
        } while ($hasNext && $after !== null);

        $this->enrichWithCrmMeta($bin, $built);

        $items = array_values($built);
        usort($items, static function (array $a, array $b): int {
            return strcasecmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? ''));
        });

        $syncedAt = now()->toIso8601String();
        $snapshot = [
            'synced_at' => $syncedAt,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'location_name' => $locationName,
            'items' => $items,
        ];
        $this->putCachedSnapshot($bin, $snapshot);

        return [
            'data' => $items,
            'source' => 'shiphero',
            'synced_at' => $syncedAt,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'needs_sync' => false,
        ];
    }

    public function adjustCachedItemQty(ReturnBin $bin, string $sku, int $clientAccountId, int $delta): void
    {
        $cached = $this->getCachedSnapshot($bin);
        if (! is_array($cached) || ! isset($cached['items']) || ! is_array($cached['items'])) {
            return;
        }

        $skuKey = mb_strtolower(trim($sku));
        $items = [];
        foreach ($cached['items'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowSku = mb_strtolower(trim((string) ($row['sku'] ?? '')));
            $rowAccount = (int) ($row['client_account_id'] ?? 0);
            if ($rowSku === $skuKey && $rowAccount === $clientAccountId) {
                $qty = max(0, (int) ($row['qty'] ?? 0) + $delta);
                if ($qty <= 0) {
                    continue;
                }
                $row['qty'] = $qty;
            }
            $items[] = $row;
        }
        $cached['items'] = $items;
        $this->putCachedSnapshot($bin, $cached);
    }

    public function resolveReturnsWarehouseId(): string
    {
        foreach ([
            config('services.shiphero.returns_warehouse_id'),
            config('services.shiphero.put_away_warehouse_id'),
            config('services.shiphero.restock_warehouse_id'),
        ] as $candidate) {
            $id = trim((string) $candidate);
            if ($id !== '') {
                return $id;
            }
        }

        return $this->restockReports->resolveWarehouseIdForApi(null);
    }

    /**
     * @param  array<string, array<string, mixed>>  $built
     */
    private function enrichWithCrmMeta(ReturnBin $bin, array &$built): void
    {
        if ($built === []) {
            return;
        }

        $accountIds = [];
        foreach ($built as $row) {
            $id = (int) ($row['client_account_id'] ?? 0);
            if ($id > 0) {
                $accountIds[$id] = true;
            }
        }
        $companyById = $accountIds === []
            ? collect()
            : ClientAccount::query()
                ->whereIn('id', array_keys($accountIds))
                ->pluck('company_name', 'id');

        $crmRows = ClientAccountReturnLine::query()
            ->join('client_account_returns', 'client_account_returns.id', '=', 'client_account_return_lines.client_account_return_id')
            ->where('client_account_return_lines.return_bin_id', $bin->id)
            ->where('client_account_return_lines.return_bin_remaining_qty', '>', 0)
            ->groupBy(
                'client_account_return_lines.sku',
                'client_account_returns.client_account_id'
            )
            ->selectRaw('client_account_return_lines.sku as sku')
            ->selectRaw('client_account_returns.client_account_id as client_account_id')
            ->selectRaw('MAX(client_account_return_lines.pick_location) as pick_location')
            ->selectRaw('MAX(client_account_return_lines.name) as name')
            ->selectRaw('MAX(client_account_return_lines.image_url) as image_url')
            ->get()
            ->keyBy(fn ($row) => ((int) $row->client_account_id).'|'.mb_strtolower((string) $row->sku));

        foreach ($built as $key => &$row) {
            $accountId = (int) ($row['client_account_id'] ?? 0);
            if ($accountId > 0) {
                $row['client_account_company_name'] = trim((string) ($companyById[$accountId] ?? ''));
            }
            $crm = $crmRows->get($key);
            if ($crm === null) {
                continue;
            }
            $pick = trim((string) ($crm->pick_location ?? ''));
            if ($pick !== '' && $pick !== '—') {
                $row['pick_location'] = $pick;
            }
            if (trim((string) ($row['name'] ?? '')) === '' || $row['name'] === $row['sku']) {
                $name = trim((string) ($crm->name ?? ''));
                if ($name !== '') {
                    $row['name'] = $name;
                }
            }
            if (empty($row['image_url'])) {
                $image = trim((string) ($crm->image_url ?? ''));
                $row['image_url'] = $image !== '' ? $image : null;
            }
        }
        unset($row);
    }

    /**
     * @return array{client_account_id: ?int, name: ?string, barcode: ?string, image_url: ?string}
     */
    private function localProductMetaForSku(string $sku): array
    {
        $skuSearch = mb_strtolower(trim($sku));
        $empty = [
            'client_account_id' => null,
            'name' => null,
            'barcode' => null,
            'image_url' => null,
        ];
        if ($skuSearch === '') {
            return $empty;
        }

        $indexRow = ShipHeroInventoryProductIndex::query()
            ->where('sku_search', $skuSearch)
            ->orderByDesc('synced_at')
            ->first(['client_account_id', 'name', 'barcode', 'image_url']);

        if ($indexRow === null) {
            $indexRow = ShipHeroInventoryProductIndex::query()
                ->whereRaw('LOWER(sku) = ?', [$skuSearch])
                ->orderByDesc('synced_at')
                ->first(['client_account_id', 'name', 'barcode', 'image_url']);
        }

        if ($indexRow === null) {
            return $empty;
        }

        return [
            'client_account_id' => $indexRow->client_account_id !== null ? (int) $indexRow->client_account_id : null,
            'name' => $indexRow->name !== null && trim((string) $indexRow->name) !== ''
                ? trim((string) $indexRow->name)
                : null,
            'barcode' => $indexRow->barcode !== null && trim((string) $indexRow->barcode) !== ''
                ? trim((string) $indexRow->barcode)
                : null,
            'image_url' => $indexRow->image_url !== null && trim((string) $indexRow->image_url) !== ''
                ? trim((string) $indexRow->image_url)
                : null,
        ];
    }

    private function cacheKey(ReturnBin $bin): string
    {
        return 'return_bin_shiphero_items:v1:'.(int) $bin->id;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getCachedSnapshot(ReturnBin $bin): ?array
    {
        $cached = Cache::get($this->cacheKey($bin));

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function putCachedSnapshot(ReturnBin $bin, array $snapshot): void
    {
        Cache::put($this->cacheKey($bin), $snapshot, now()->addHours(24));
    }
}
