<?php

namespace App\Services;

use App\Jobs\TransferReturnBinItemJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\ReturnBin;
use App\Models\User;
use App\Support\InventoryAdjustmentActor;
use App\Support\PutAwayRowBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReturnBinService
{
    public const BIN_RETURN_CART = 'Return Cart';

    public const BIN_DISPOSE = 'Dispose Bin';

    /** @var ShipHeroInventoryService */
    private $inventory;

    /** @var InventoryRestockReportService */
    private $restockReports;

    /** @var ReturnBinLocationSyncService */
    private $locationSync;

    public function __construct(
        ShipHeroInventoryService $inventory,
        InventoryRestockReportService $restockReports,
        ReturnBinLocationSyncService $locationSync
    ) {
        $this->inventory = $inventory;
        $this->restockReports = $restockReports;
        $this->locationSync = $locationSync;
    }

    public function restockStagingLocationName(): string
    {
        $name = trim((string) config('returns.staging_locations.restock', self::BIN_RETURN_CART));

        return $name !== '' ? $name : self::BIN_RETURN_CART;
    }

    public function disposeStagingLocationName(): string
    {
        $name = trim((string) config('returns.staging_locations.dispose', self::BIN_DISPOSE));

        return $name !== '' ? $name : self::BIN_DISPOSE;
    }

    public function stagingLocationName(bool $restock): string
    {
        return $restock ? $this->restockStagingLocationName() : $this->disposeStagingLocationName();
    }

    public function findOrCreateNamedBin(string $name): ReturnBin
    {
        $name = $this->normalizeBinName($name);
        $existing = ReturnBin::query()->where('name', $name)->first();
        if ($existing instanceof ReturnBin) {
            return $existing;
        }

        return ReturnBin::query()->create(['name' => $name]);
    }

    public function stagingBinForRestock(bool $restock): ReturnBin
    {
        return $this->findOrCreateNamedBin($this->stagingLocationName($restock));
    }

    /**
     * Map each returned line to Return Cart or Dispose Bin CRM tracking bins.
     *
     * @return array<int, int> line id => return_bin id
     */
    public function resolveStagingBinIdsForReturn(ClientAccountReturn $return): array
    {
        $binIdByLineId = [];
        $cache = [];
        foreach ($return->lines as $line) {
            if ((int) $line->return_qty <= 0) {
                continue;
            }
            $restock = (bool) $line->restock;
            $key = $restock ? '1' : '0';
            if (! isset($cache[$key])) {
                $cache[$key] = (int) $this->stagingBinForRestock($restock)->id;
            }
            $binIdByLineId[(int) $line->id] = $cache[$key];
        }

        return $binIdByLineId;
    }

    /**
     * Add processed return qty into ShipHero Return Cart / Dispose Bin locations.
     */
    public function addProcessedQtyToShipHeroStaging(ClientAccountReturn $return, ?User $actor): void
    {
        $return->loadMissing(['lines', 'clientAccount']);
        $account = $return->clientAccount;
        if (! $account instanceof ClientAccount) {
            throw ValidationException::withMessages([
                'client_account_id' => ['Return account is required.'],
            ]);
        }
        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            throw ValidationException::withMessages([
                'client_account_id' => ['This account is not linked to ShipHero.'],
            ]);
        }

        $warehouseId = $this->resolveReturnsWarehouseId();

        foreach ($return->lines as $line) {
            $qty = (int) $line->return_qty;
            if ($qty <= 0) {
                continue;
            }
            $sku = trim((string) $line->sku);
            if ($sku === '') {
                continue;
            }

            $locationName = $this->stagingLocationName((bool) $line->restock);
            $resolved = $this->resolveInventoryLocation($sku, $warehouseId, $locationName, $customerId);
            if (! is_array($resolved) || trim((string) ($resolved['id'] ?? '')) === '') {
                throw new RuntimeException(
                    'Could not resolve ShipHero location "'.$locationName.'" for SKU '.$sku.'.'
                );
            }

            $locationId = trim((string) $resolved['id']);
            $resolvedName = trim((string) ($resolved['name'] ?? '')) ?: $locationName;
            $reason = $this->restockReasonForReturn($return, $actor);

            $this->inventory->addLocationQuantity(
                $sku,
                $warehouseId,
                $locationId,
                $qty,
                $reason,
                $customerId
            );

            // Staging destination is the CRM bin + ShipHero location; keep product pick
            // locations on the line for the Return Bin detail "Pick Location" column.
            $existingPick = trim((string) ($line->pick_location ?? ''));
            if ($existingPick === '' || $existingPick === '—') {
                $line->pick_location = $resolvedName;
                $line->save();
            }
        }
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
     * @return list<array{id: int, name: string, items_count: int}>
     */
    public function listBins(): array
    {
        $counts = ClientAccountReturnLine::query()
            ->whereNotNull('return_bin_id')
            ->where('return_bin_remaining_qty', '>', 0)
            ->groupBy('return_bin_id')
            ->selectRaw('return_bin_id, SUM(return_bin_remaining_qty) as items_count')
            ->pluck('items_count', 'return_bin_id');

        return ReturnBin::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ReturnBin $bin) => $bin->toListArray((int) ($counts[$bin->id] ?? 0)))
            ->values()
            ->all();
    }

    public function createBin(string $name): ReturnBin
    {
        $name = $this->normalizeBinName($name);

        if (ReturnBin::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A return bin with this name already exists.'],
            ]);
        }

        return ReturnBin::query()->create(['name' => $name]);
    }

    public function renameBin(ReturnBin $bin, string $name): ReturnBin
    {
        $name = $this->normalizeBinName($name);

        $duplicate = ReturnBin::query()
            ->where('name', $name)
            ->where('id', '!=', $bin->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => ['A return bin with this name already exists.'],
            ]);
        }

        $bin->name = $name;
        $bin->save();

        return $bin->fresh();
    }

    public function clearBin(ReturnBin $bin): ReturnBin
    {
        return DB::transaction(function () use ($bin) {
            ClientAccountReturnLine::query()
                ->where('return_bin_id', $bin->id)
                ->update([
                    'return_bin_remaining_qty' => 0,
                    'return_bin_id' => null,
                    'return_bin_number' => null,
                ]);

            ClientAccountReturn::query()
                ->where('return_bin_id', $bin->id)
                ->update([
                    'return_bin_id' => null,
                    'return_bin_number' => null,
                ]);

            return $bin->fresh();
        });
    }

    public function deleteBin(ReturnBin $bin): void
    {
        $itemsCount = $this->itemsCountForBin((int) $bin->id);
        if ($itemsCount > 0) {
            throw ValidationException::withMessages([
                'bin' => ['Clear all items from this bin before deleting it.'],
            ]);
        }

        DB::transaction(function () use ($bin) {
            ClientAccountReturnLine::query()
                ->where('return_bin_id', $bin->id)
                ->update([
                    'return_bin_id' => null,
                    'return_bin_number' => null,
                ]);

            ClientAccountReturn::query()
                ->where('return_bin_id', $bin->id)
                ->update([
                    'return_bin_id' => null,
                    'return_bin_number' => null,
                ]);

            $bin->delete();
        });
    }

    public function itemsCountForBin(int $binId): int
    {
        return (int) ClientAccountReturnLine::query()
            ->where('return_bin_id', $binId)
            ->where('return_bin_remaining_qty', '>', 0)
            ->sum('return_bin_remaining_qty');
    }

    /**
     * Fast DB-only aggregation — no ShipHero calls.
     *
     * @return list<array<string, mixed>>
     */
    public function listBinItems(ReturnBin $bin): array
    {
        $rows = ClientAccountReturnLine::query()
            ->join('client_account_returns', 'client_account_returns.id', '=', 'client_account_return_lines.client_account_return_id')
            ->join('client_accounts', 'client_accounts.id', '=', 'client_account_returns.client_account_id')
            ->where('client_account_return_lines.return_bin_id', $bin->id)
            ->where('client_account_return_lines.return_bin_remaining_qty', '>', 0)
            ->groupBy(
                'client_account_return_lines.sku',
                'client_account_return_lines.name',
                'client_account_returns.client_account_id',
                'client_accounts.company_name'
            )
            ->selectRaw('client_account_return_lines.sku as sku')
            ->selectRaw('client_account_return_lines.name as name')
            ->selectRaw('MAX(client_account_return_lines.image_url) as image_url')
            ->selectRaw('MAX(client_account_return_lines.pick_location) as pick_location')
            ->selectRaw('client_account_returns.client_account_id as client_account_id')
            ->selectRaw('client_accounts.company_name as client_account_company_name')
            ->selectRaw('SUM(client_account_return_lines.return_bin_remaining_qty) as qty')
            ->orderBy('client_account_return_lines.sku')
            ->get();

        return $rows->map(function ($row) {
            $pick = trim((string) ($row->pick_location ?? ''));

            return [
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'image_url' => $row->image_url !== null && trim((string) $row->image_url) !== ''
                    ? trim((string) $row->image_url)
                    : null,
                'qty' => (int) $row->qty,
                'client_account_id' => (int) $row->client_account_id,
                'client_account_company_name' => trim((string) $row->client_account_company_name),
                'pick_location' => $pick !== '' ? $pick : '—',
                'warehouse_id' => null,
                'from_location_id' => null,
            ];
        })->values()->all();
    }

    /**
     * Prefer ShipHero location snapshot when available.
     *
     * @return array{
     *   data: list<array<string, mixed>>,
     *   source: string,
     *   synced_at: ?string,
     *   warehouse_id: ?string,
     *   location_id: ?string,
     *   needs_sync: bool
     * }
     */
    public function listBinItemsForDisplay(ReturnBin $bin): array
    {
        return $this->locationSync->listForDisplay($bin, function (ReturnBin $b) {
            return $this->listBinItems($b);
        });
    }

    /**
     * Sync what is in the ShipHero location named like this bin (e.g. Return Cart).
     *
     * @return array{
     *   data: list<array<string, mixed>>,
     *   source: string,
     *   synced_at: string,
     *   warehouse_id: string,
     *   location_id: ?string,
     *   needs_sync: bool
     * }
     */
    public function syncBinItemsFromShipHero(ReturnBin $bin): array
    {
        return $this->locationSync->syncFromShipHero($bin);
    }

    public function adjustCachedBinItemQty(ReturnBin $bin, string $sku, int $clientAccountId, int $delta): void
    {
        $this->locationSync->adjustCachedItemQty($bin, $sku, $clientAccountId, $delta);
    }

    /**
     * Assign each returned line to a bin. Lines may use different bins.
     *
     * @param  array<int, int>  $binIdByLineId  line id => return_bin id
     */
    public function assignLinesToBins(ClientAccountReturn $return, array $binIdByLineId): ClientAccountReturn
    {
        $this->assertReceivedReturn($return);

        if ($binIdByLineId === []) {
            throw ValidationException::withMessages([
                'return_bin_id' => ['Select a return bin for each returned item.'],
            ]);
        }

        $binIds = array_values(array_unique(array_filter(array_map('intval', $binIdByLineId), function ($id) {
            return $id > 0;
        })));
        if ($binIds === []) {
            throw ValidationException::withMessages([
                'return_bin_id' => ['Select a return bin for each returned item.'],
            ]);
        }

        $bins = ReturnBin::query()->whereIn('id', $binIds)->get()->keyBy('id');
        foreach ($binIds as $binId) {
            if (! $bins->has($binId)) {
                throw ValidationException::withMessages([
                    'return_bin_id' => ['Select a valid return bin.'],
                ]);
            }
        }

        return DB::transaction(function () use ($return, $binIdByLineId, $bins, $binIds) {
            $primaryBinId = (int) $binIds[0];
            $return->return_bin_id = $primaryBinId;
            $return->return_bin_number = null;
            $return->save();

            $lines = ClientAccountReturnLine::query()
                ->where('client_account_return_id', $return->id)
                ->get();

            $accountId = (int) $return->client_account_id;
            $pickCache = [];

            foreach ($lines as $line) {
                if ((int) $line->return_qty <= 0) {
                    continue;
                }
                $lineId = (int) $line->id;
                $binId = (int) ($binIdByLineId[$lineId] ?? 0);
                if ($binId <= 0 || ! $bins->has($binId)) {
                    throw ValidationException::withMessages([
                        'return_bin_id' => ['Select a return bin for each returned item.'],
                    ]);
                }

                if ($line->return_bin_remaining_qty === null) {
                    $line->return_bin_remaining_qty = (int) $line->return_qty;
                }
                if ((int) $line->return_bin_remaining_qty > 0) {
                    $line->return_bin_id = $binId;
                    $line->return_bin_number = null;
                    $sku = trim((string) $line->sku);
                    if ($sku !== '' && (trim((string) ($line->pick_location ?? '')) === '' || $line->pick_location === '—')) {
                        $cacheKey = $sku.'|'.$accountId;
                        if (! array_key_exists($cacheKey, $pickCache)) {
                            $pickCache[$cacheKey] = $this->resolvePickLocationLabel($sku, $accountId);
                        }
                        $label = $pickCache[$cacheKey] ?? '—';
                        if ($label !== '' && $label !== '—') {
                            $line->pick_location = $label;
                        }
                    }
                    $line->save();
                }
            }

            return $return->fresh(['lines', 'clientAccount', 'returnBin']);
        });
    }

    public function assignReturnToBin(ClientAccountReturn $return, int $binId): ClientAccountReturn
    {
        $lines = ClientAccountReturnLine::query()
            ->where('client_account_return_id', $return->id)
            ->where('return_qty', '>', 0)
            ->pluck('id');

        $binIdByLineId = [];
        foreach ($lines as $lineId) {
            $binIdByLineId[(int) $lineId] = $binId;
        }

        return $this->assignLinesToBins($return, $binIdByLineId);
    }

    /**
     * Fast path: decrement CRM + optimistic ShipHero cache, transfer once in background.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function transferFromBin(ReturnBin $bin, array $payload, ?User $actor): array
    {
        $sku = trim((string) ($payload['sku'] ?? ''));
        $clientAccountId = (int) ($payload['client_account_id'] ?? 0);
        $quantity = (int) ($payload['quantity'] ?? 0);
        $warehouseId = trim((string) ($payload['warehouse_id'] ?? ''));
        $toLocationId = trim((string) ($payload['to_location_id'] ?? ''));
        $toLocationName = trim((string) ($payload['to_location'] ?? ''));
        $background = ! array_key_exists('background', $payload) || (bool) $payload['background'];

        if ($sku === '' || $clientAccountId <= 0) {
            throw ValidationException::withMessages([
                'sku' => ['SKU and client account are required.'],
            ]);
        }
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Enter a quantity greater than zero.'],
            ]);
        }
        if ($warehouseId === '') {
            $warehouseId = $this->locationSync->resolveReturnsWarehouseId();
        }
        if ($toLocationId === '' && $toLocationName === '') {
            throw ValidationException::withMessages([
                'to_location' => ['Select or enter a destination location.'],
            ]);
        }

        $account = ClientAccount::query()->findOrFail($clientAccountId);
        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            throw ValidationException::withMessages([
                'client_account_id' => ['This account is not linked to ShipHero.'],
            ]);
        }

        $display = $this->listBinItemsForDisplay($bin);
        $shipQty = $this->qtyOnDisplayForSku($display['data'], $sku, $clientAccountId);
        $crmAvailable = (int) ClientAccountReturnLine::query()
            ->join('client_account_returns', 'client_account_returns.id', '=', 'client_account_return_lines.client_account_return_id')
            ->where('client_account_return_lines.return_bin_id', $bin->id)
            ->where('client_account_return_lines.return_bin_remaining_qty', '>', 0)
            ->where('client_account_return_lines.sku', $sku)
            ->where('client_account_returns.client_account_id', $clientAccountId)
            ->sum('client_account_return_lines.return_bin_remaining_qty');

        $maxAllowed = max($shipQty, $crmAvailable);
        if ($maxAllowed <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['No quantity available in this bin for that SKU.'],
            ]);
        }
        if ($quantity > $maxAllowed) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity exceeds available items in this bin ('.$maxAllowed.').'],
            ]);
        }

        $resolvedLocationName = $toLocationName;
        if ($toLocationId === '') {
            $resolved = $this->resolveInventoryLocation(
                $sku,
                $warehouseId,
                $toLocationName,
                $customerId
            );
            if (! is_array($resolved) || trim((string) ($resolved['id'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'to_location' => ['Location not found in this warehouse.'],
                ]);
            }
            $toLocationId = trim((string) $resolved['id']);
            if ($resolvedLocationName === '') {
                $resolvedLocationName = trim((string) ($resolved['name'] ?? ''));
            }
        } elseif ($resolvedLocationName === '') {
            $resolvedLocationName = $this->resolveLocationNameById($sku, $warehouseId, $toLocationId, $customerId);
        }

        $fromLocationId = trim((string) ($payload['from_location_id'] ?? ''));
        if ($fromLocationId === '' && is_string($display['location_id'] ?? null)) {
            $fromLocationId = trim((string) $display['location_id']);
        }
        if ($fromLocationId === '') {
            $fromRowId = $this->fromLocationIdOnDisplay($display['data'], $sku, $clientAccountId);
            if ($fromRowId !== '') {
                $fromLocationId = $fromRowId;
            }
        }
        if ($fromLocationId === '') {
            $stagingName = trim((string) $bin->name);
            if ($stagingName !== '') {
                $fromResolved = $this->resolveInventoryLocation($sku, $warehouseId, $stagingName, $customerId);
                if (is_array($fromResolved) && trim((string) ($fromResolved['id'] ?? '')) !== '') {
                    $fromLocationId = trim((string) $fromResolved['id']);
                }
            }
        }
        if ($fromLocationId === '') {
            throw ValidationException::withMessages([
                'from_location_id' => ['Could not resolve the '.$bin->name.' location in ShipHero.'],
            ]);
        }

        $reason = 'Return Restock';
        $this->decrementCrmBinQty(
            $bin,
            $sku,
            $clientAccountId,
            $quantity,
            $resolvedLocationName,
            $actor,
            $reason
        );

        $this->adjustCachedBinItemQty($bin, $sku, $clientAccountId, -$quantity);

        $queued = false;
        if ($background) {
            TransferReturnBinItemJob::dispatch([
                'return_bin_id' => (int) $bin->id,
                'sku' => $sku,
                'client_account_id' => $clientAccountId,
                'warehouse_id' => $warehouseId,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'quantity' => $quantity,
                'reason' => $reason,
                'shiphero_customer_id' => $customerId,
            ]);
            $queued = true;
        } else {
            $this->inventory->transferLocationQuantity(
                $sku,
                $warehouseId,
                $fromLocationId,
                $toLocationId,
                $quantity,
                $reason,
                $customerId
            );
        }

        $fresh = $this->listBinItemsForDisplay($bin);
        $remainingAfter = $this->qtyOnDisplayForSku($fresh['data'], $sku, $clientAccountId);

        return array_merge($fresh, [
            'transferred_qty' => $quantity,
            'remaining_qty' => $remainingAfter,
            'queued' => $queued,
        ]);
    }

    /**
     * @return int CRM qty actually decremented
     */
    private function decrementCrmBinQty(
        ReturnBin $bin,
        string $sku,
        int $clientAccountId,
        int $quantity,
        string $resolvedLocationName,
        ?User $actor,
        string &$reason
    ): int {
        return (int) DB::transaction(function () use (
            $bin,
            $sku,
            $clientAccountId,
            $quantity,
            $resolvedLocationName,
            $actor,
            &$reason
        ) {
            $remainingToTransfer = $quantity;
            $transferred = 0;

            $lines = ClientAccountReturnLine::query()
                ->where('return_bin_id', $bin->id)
                ->where('return_bin_remaining_qty', '>', 0)
                ->where('sku', $sku)
                ->whereHas('clientAccountReturn', function ($query) use ($clientAccountId) {
                    $query->where('client_account_id', $clientAccountId);
                })
                ->orderBy('id')
                ->with('clientAccountReturn')
                ->lockForUpdate()
                ->get();

            foreach ($lines as $line) {
                if ($remainingToTransfer <= 0) {
                    break;
                }
                $lineRemaining = (int) $line->return_bin_remaining_qty;
                if ($lineRemaining <= 0) {
                    continue;
                }
                $chunk = min($lineRemaining, $remainingToTransfer);
                $parentReturn = $line->clientAccountReturn;
                if (! $parentReturn instanceof ClientAccountReturn) {
                    $parentReturn = ClientAccountReturn::query()->findOrFail($line->client_account_return_id);
                }
                if ($transferred === 0) {
                    $reason = $this->restockReasonForReturn($parentReturn, $actor);
                }

                $line->return_bin_remaining_qty = $lineRemaining - $chunk;
                if ($resolvedLocationName !== '') {
                    $line->pick_location = $resolvedLocationName;
                }
                if ((int) $line->return_bin_remaining_qty <= 0) {
                    $line->return_bin_id = null;
                    $line->return_bin_number = null;
                }
                $line->save();
                $transferred += $chunk;
                $remainingToTransfer -= $chunk;
            }

            return $transferred;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function qtyOnDisplayForSku(array $rows, string $sku, int $clientAccountId): int
    {
        $skuKey = mb_strtolower(trim($sku));
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (
                mb_strtolower(trim((string) ($row['sku'] ?? ''))) === $skuKey
                && (int) ($row['client_account_id'] ?? 0) === $clientAccountId
            ) {
                return max(0, (int) ($row['qty'] ?? 0));
            }
        }

        return 0;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function fromLocationIdOnDisplay(array $rows, string $sku, int $clientAccountId): string
    {
        $skuKey = mb_strtolower(trim($sku));
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (
                mb_strtolower(trim((string) ($row['sku'] ?? ''))) === $skuKey
                && (int) ($row['client_account_id'] ?? 0) === $clientAccountId
            ) {
                return trim((string) ($row['from_location_id'] ?? ''));
            }
        }

        return '';
    }

    /**
     * Remove qty from CRM bin tracking and reduce ShipHero staging location stock.
     *
     * @return array{removed_qty: int, remaining_qty: int}
     */
    public function removeFromBin(ReturnBin $bin, array $payload, ?User $actor): array
    {
        $sku = trim((string) ($payload['sku'] ?? ''));
        $clientAccountId = (int) ($payload['client_account_id'] ?? 0);
        $quantity = (int) ($payload['quantity'] ?? 0);

        if ($sku === '' || $clientAccountId <= 0) {
            throw ValidationException::withMessages([
                'sku' => ['SKU and client account are required.'],
            ]);
        }
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Enter a quantity greater than zero.'],
            ]);
        }

        $available = (int) ClientAccountReturnLine::query()
            ->join('client_account_returns', 'client_account_returns.id', '=', 'client_account_return_lines.client_account_return_id')
            ->where('client_account_return_lines.return_bin_id', $bin->id)
            ->where('client_account_return_lines.return_bin_remaining_qty', '>', 0)
            ->where('client_account_return_lines.sku', $sku)
            ->where('client_account_returns.client_account_id', $clientAccountId)
            ->sum('client_account_return_lines.return_bin_remaining_qty');

        if ($quantity > $available) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity exceeds available items in this bin ('.$available.').'],
            ]);
        }

        $account = ClientAccount::query()->findOrFail($clientAccountId);
        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            throw ValidationException::withMessages([
                'client_account_id' => ['This account is not linked to ShipHero.'],
            ]);
        }

        $stagingName = trim((string) $bin->name);
        if ($stagingName === '') {
            throw ValidationException::withMessages([
                'bin' => ['Return bin name is missing.'],
            ]);
        }

        try {
            $product = $this->inventory->getProductDetailBySku($sku, null, $customerId);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'sku' => ['Could not load product locations from ShipHero.'],
            ]);
        }
        if (! is_array($product)) {
            throw ValidationException::withMessages([
                'sku' => ['Product not found in ShipHero.'],
            ]);
        }

        $stagingLoc = null;
        foreach ($this->flattenProductLocations($product) as $loc) {
            $name = trim((string) ($loc['location_name'] ?? ''));
            if (strcasecmp($name, $stagingName) !== 0) {
                continue;
            }
            if ((int) ($loc['quantity'] ?? 0) <= 0) {
                continue;
            }
            $stagingLoc = $loc;
            break;
        }
        if ($stagingLoc === null) {
            throw ValidationException::withMessages([
                'quantity' => ['No '.$stagingName.' quantity found for this SKU in ShipHero.'],
            ]);
        }

        $warehouseId = trim((string) ($stagingLoc['warehouse_id'] ?? ''));
        $fromLocationId = trim((string) ($stagingLoc['location_id'] ?? ''));
        $shipHeroQty = (int) ($stagingLoc['quantity'] ?? 0);
        if ($warehouseId === '' || $fromLocationId === '') {
            throw ValidationException::withMessages([
                'quantity' => ['Could not resolve the '.$stagingName.' location for this SKU.'],
            ]);
        }
        if ($shipHeroQty < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => ['ShipHero '.$stagingName.' only has '.$shipHeroQty.' available.'],
            ]);
        }

        $reason = InventoryAdjustmentActor::reasonWithActor($stagingName.' Delete', $actor);

        return DB::transaction(function () use (
            $bin,
            $sku,
            $clientAccountId,
            $quantity,
            $warehouseId,
            $fromLocationId,
            $shipHeroQty,
            $customerId,
            $reason
        ) {
            $this->inventory->replaceLocationQuantity(
                $sku,
                $warehouseId,
                $fromLocationId,
                max(0, $shipHeroQty - $quantity),
                $reason,
                $customerId
            );

            $remainingToRemove = $quantity;
            $removed = 0;

            $lines = ClientAccountReturnLine::query()
                ->where('return_bin_id', $bin->id)
                ->where('return_bin_remaining_qty', '>', 0)
                ->where('sku', $sku)
                ->whereHas('clientAccountReturn', function ($query) use ($clientAccountId) {
                    $query->where('client_account_id', $clientAccountId);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($lines as $line) {
                if ($remainingToRemove <= 0) {
                    break;
                }
                $lineRemaining = (int) $line->return_bin_remaining_qty;
                if ($lineRemaining <= 0) {
                    continue;
                }
                $chunk = min($lineRemaining, $remainingToRemove);
                $line->return_bin_remaining_qty = $lineRemaining - $chunk;
                if ((int) $line->return_bin_remaining_qty <= 0) {
                    $line->return_bin_id = null;
                    $line->return_bin_number = null;
                }
                $line->save();
                $removed += $chunk;
                $remainingToRemove -= $chunk;
            }

            if ($removed !== $quantity) {
                throw new RuntimeException('Could not remove the requested quantity from the return bin.');
            }

            $remainingAfter = (int) ClientAccountReturnLine::query()
                ->join('client_account_returns', 'client_account_returns.id', '=', 'client_account_return_lines.client_account_return_id')
                ->where('client_account_return_lines.return_bin_id', $bin->id)
                ->where('client_account_return_lines.return_bin_remaining_qty', '>', 0)
                ->where('client_account_return_lines.sku', $sku)
                ->where('client_account_returns.client_account_id', $clientAccountId)
                ->sum('client_account_return_lines.return_bin_remaining_qty');

            $this->adjustCachedBinItemQty($bin, $sku, $clientAccountId, -$removed);

            return [
                'removed_qty' => $removed,
                'remaining_qty' => $remainingAfter,
            ];
        });
    }

    public function restockReasonForReturn(ClientAccountReturn $return, ?User $actor): string
    {
        $prefix = $return->isNonCompliant()
            ? 'Return Restock (Non-Compliant)'
            : 'Return Restock RMA# '.trim((string) $return->rma_number);

        return InventoryAdjustmentActor::reasonWithActor($prefix, $actor);
    }

    public function findBinOrFail(int $binId): ReturnBin
    {
        $bin = ReturnBin::query()->find($binId);
        if (! $bin instanceof ReturnBin) {
            throw ValidationException::withMessages([
                'bin' => ['Return bin not found.'],
            ]);
        }

        return $bin;
    }

    private function normalizeBinName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['Bin name is required.'],
            ]);
        }
        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages([
                'name' => ['Bin name may not be greater than 255 characters.'],
            ]);
        }

        return $name;
    }

    private function resolvePickLocationLabel(string $sku, int $clientAccountId): string
    {
        $account = ClientAccount::query()->find($clientAccountId);
        $customerId = $account !== null
            ? trim((string) $account->shiphero_customer_account_id)
            : '';

        try {
            $product = $this->inventory->getProductDetailBySku($sku, null, $customerId !== '' ? $customerId : null);
        } catch (\Throwable $e) {
            return '—';
        }
        if (! is_array($product)) {
            return '—';
        }

        $locations = $this->flattenProductLocations($product);
        $label = PutAwayRowBuilder::pickLocationLabel($locations);

        return $label !== null && $label !== '' ? $label : '—';
    }

    /**
     * @param  array<string, mixed>  $product
     * @return list<array<string, mixed>>
     */
    private function flattenProductLocations(array $product): array
    {
        $out = [];
        foreach ($product['warehouses'] ?? [] as $wh) {
            if (! is_array($wh)) {
                continue;
            }
            foreach ($wh['locations'] ?? [] as $loc) {
                if (! is_array($loc)) {
                    continue;
                }
                $out[] = array_merge($loc, [
                    'warehouse_id' => $wh['warehouse_id'] ?? null,
                    'warehouse_name' => $wh['warehouse_name'] ?? null,
                ]);
            }
        }

        return $out;
    }

    /**
     * Resolve a warehouse location the same way inventory transfers do:
     * warehouse catalog first, then product locations, then unscoped warehouse lookup.
     *
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}|null
     */
    private function resolveInventoryLocation(
        string $sku,
        string $warehouseId,
        string $locationInput,
        ?string $customerAccountId
    ): ?array {
        $resolved = $this->inventory->resolveWarehouseLocation($warehouseId, $locationInput, $customerAccountId);
        if (is_array($resolved)) {
            return $resolved;
        }

        $resolved = $this->inventory->resolveProductWarehouseLocation(
            $sku,
            $warehouseId,
            $locationInput,
            $customerAccountId
        );
        if (is_array($resolved)) {
            return $resolved;
        }

        if (is_string($customerAccountId) && trim($customerAccountId) !== '') {
            $resolved = $this->inventory->resolveWarehouseLocation($warehouseId, $locationInput, null);
            if (is_array($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    private function resolveLocationNameById(
        string $sku,
        string $warehouseId,
        string $locationId,
        string $customerId
    ): string {
        try {
            $product = $this->inventory->getProductDetailBySku($sku, $warehouseId, $customerId);
        } catch (\Throwable $e) {
            return '';
        }
        if (! is_array($product)) {
            return '';
        }

        foreach ($this->flattenProductLocations($product) as $loc) {
            if (trim((string) ($loc['warehouse_id'] ?? '')) !== $warehouseId) {
                continue;
            }
            if (trim((string) ($loc['location_id'] ?? '')) !== $locationId) {
                continue;
            }

            return trim((string) ($loc['location_name'] ?? ''));
        }

        return '';
    }

    private function assertReceivedReturn(ClientAccountReturn $return): void
    {
        if (! in_array($return->status, [ClientAccountReturn::STATUS_RECEIVED, ClientAccountReturn::STATUS_COMPLETED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only processed returns can be assigned to a return bin.'],
            ]);
        }
    }
}
