<?php

namespace App\Services;

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
    /** @var ShipHeroInventoryService */
    private $inventory;

    public function __construct(ShipHeroInventoryService $inventory)
    {
        $this->inventory = $inventory;
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
            ];
        })->values()->all();
    }

    public function assignReturnToBin(ClientAccountReturn $return, int $binId): ClientAccountReturn
    {
        $this->assertReceivedReturn($return);
        $bin = ReturnBin::query()->find($binId);
        if (! $bin instanceof ReturnBin) {
            throw ValidationException::withMessages([
                'return_bin_id' => ['Select a valid return bin.'],
            ]);
        }

        return DB::transaction(function () use ($return, $bin) {
            $return->return_bin_id = $bin->id;
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
                if ($line->return_bin_remaining_qty === null) {
                    $line->return_bin_remaining_qty = (int) $line->return_qty;
                }
                if ((int) $line->return_bin_remaining_qty > 0) {
                    $line->return_bin_id = $bin->id;
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array{transferred_qty: int, remaining_qty: int}
     */
    public function transferFromBin(ReturnBin $bin, array $payload, ?User $actor): array
    {
        $sku = trim((string) ($payload['sku'] ?? ''));
        $clientAccountId = (int) ($payload['client_account_id'] ?? 0);
        $quantity = (int) ($payload['quantity'] ?? 0);
        $warehouseId = trim((string) ($payload['warehouse_id'] ?? ''));
        $toLocationId = trim((string) ($payload['to_location_id'] ?? ''));
        $toLocationName = trim((string) ($payload['to_location'] ?? ''));

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
            throw ValidationException::withMessages([
                'warehouse_id' => ['Warehouse is required.'],
            ]);
        }
        if ($toLocationId === '' && $toLocationName === '') {
            throw ValidationException::withMessages([
                'to_location' => ['Select or enter a destination location.'],
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

        $resolvedLocationName = $toLocationName;
        if ($toLocationId === '') {
            $toLocationId = $this->resolveLocationIdByName($sku, $warehouseId, $toLocationName, $customerId);
            if ($toLocationId === '') {
                throw ValidationException::withMessages([
                    'to_location' => ['Location not found in this warehouse.'],
                ]);
            }
        } elseif ($resolvedLocationName === '') {
            $resolvedLocationName = $this->resolveLocationNameById($sku, $warehouseId, $toLocationId, $customerId);
        }

        return DB::transaction(function () use (
            $bin,
            $sku,
            $clientAccountId,
            $quantity,
            $warehouseId,
            $toLocationId,
            $resolvedLocationName,
            $customerId,
            $actor
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
                $reason = $this->restockReasonForReturn($parentReturn, $actor);

                $this->inventory->addLocationQuantity(
                    $sku,
                    $warehouseId,
                    $toLocationId,
                    $chunk,
                    $reason,
                    $customerId
                );

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

            if ($transferred !== $quantity) {
                throw new RuntimeException('Could not transfer the requested quantity from the return bin.');
            }

            $remainingAfter = (int) ClientAccountReturnLine::query()
                ->join('client_account_returns', 'client_account_returns.id', '=', 'client_account_return_lines.client_account_return_id')
                ->where('client_account_return_lines.return_bin_id', $bin->id)
                ->where('client_account_return_lines.return_bin_remaining_qty', '>', 0)
                ->where('client_account_return_lines.sku', $sku)
                ->where('client_account_returns.client_account_id', $clientAccountId)
                ->sum('client_account_return_lines.return_bin_remaining_qty');

            return [
                'transferred_qty' => $transferred,
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

    private function resolveLocationIdByName(
        string $sku,
        string $warehouseId,
        string $locationName,
        string $customerId
    ): string {
        $needle = strtolower(trim($locationName));
        if ($needle === '') {
            return '';
        }

        $product = $this->inventory->getProductDetailBySku($sku, $warehouseId, $customerId);
        if (! is_array($product)) {
            return '';
        }

        foreach ($this->flattenProductLocations($product) as $loc) {
            if (trim((string) ($loc['warehouse_id'] ?? '')) !== $warehouseId) {
                continue;
            }
            $name = strtolower(trim((string) ($loc['location_name'] ?? '')));
            if ($name !== $needle) {
                continue;
            }
            $id = trim((string) ($loc['location_id'] ?? ''));
            if ($id !== '') {
                return $id;
            }
        }

        return '';
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
