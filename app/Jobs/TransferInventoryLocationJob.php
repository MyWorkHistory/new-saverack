<?php

namespace App\Jobs;

use App\Services\InventoryProductDetailCacheService;
use App\Services\InventoryRestockBetaService;
use App\Services\PutAwayInventoryService;
use App\Services\ShipHeroInventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class TransferInventoryLocationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const RESTOCK_ERROR_CACHE_PREFIX = 'restock_transfer_error:';

    public $timeout = 300;

    public $tries = 1;

    /** @var string */
    public $sku;

    /** @var string */
    public $warehouseId;

    /** @var string */
    public $fromLocationId;

    /** @var string */
    public $fromLocationInput;

    /** @var string */
    public $toLocationId;

    /** @var string */
    public $toLocationInput;

    /** @var int */
    public $quantity;

    /** @var string */
    public $reason;

    /** @var string|null */
    public $shipheroCustomerId;

    /** @var int|null */
    public $clientAccountId;

    /** @var string|null */
    public $restockPreviousStatus;

    /** @var string|null */
    public $restockNextStatus;

    /** @var string|null */
    public $restockFromLocationName;

    /** @var string|null */
    public $restockToLocationName;

    /** @var string|null */
    public $restockSourceKind;

    /** @var string|null */
    public $restockDestinationKind;

    /** @var int|null */
    public $restockFromQtyBefore;

    /** @var int|null */
    public $restockToQtyBefore;

    /**
     * @param  array{
     *   sku: string,
     *   warehouse_id: string,
     *   from_location_id?: string|null,
     *   from_location?: string|null,
     *   to_location_id?: string|null,
     *   to_location?: string|null,
     *   quantity: int,
     *   reason: string,
     *   shiphero_customer_id?: string|null,
     *   client_account_id?: int|null,
     *   restock_previous_status?: string|null,
     *   restock_next_status?: string|null,
     *   restock_from_location_name?: string|null,
     *   restock_to_location_name?: string|null,
     *   restock_source_kind?: string|null,
     *   restock_destination_kind?: string|null,
     *   restock_from_qty_before?: int|null,
     *   restock_to_qty_before?: int|null
     * }  $payload
     */
    public function __construct(array $payload)
    {
        $this->sku = trim((string) ($payload['sku'] ?? ''));
        $this->warehouseId = trim((string) ($payload['warehouse_id'] ?? ''));
        $this->fromLocationId = trim((string) ($payload['from_location_id'] ?? ''));
        $this->fromLocationInput = trim((string) ($payload['from_location'] ?? ''));
        $this->toLocationId = trim((string) ($payload['to_location_id'] ?? ''));
        $this->toLocationInput = trim((string) ($payload['to_location'] ?? ''));
        $this->quantity = (int) ($payload['quantity'] ?? 0);
        $this->reason = (string) ($payload['reason'] ?? 'Restock');
        $customer = $payload['shiphero_customer_id'] ?? null;
        $this->shipheroCustomerId = is_string($customer) && trim($customer) !== '' ? trim($customer) : null;
        $accountId = isset($payload['client_account_id']) ? (int) $payload['client_account_id'] : null;
        $this->clientAccountId = $accountId !== null && $accountId > 0 ? $accountId : null;
        $prev = isset($payload['restock_previous_status']) ? trim((string) $payload['restock_previous_status']) : '';
        $this->restockPreviousStatus = $prev !== '' ? $prev : null;
        $next = isset($payload['restock_next_status']) ? trim((string) $payload['restock_next_status']) : '';
        $this->restockNextStatus = $next !== '' ? $next : null;
        $fromName = isset($payload['restock_from_location_name']) ? trim((string) $payload['restock_from_location_name']) : '';
        $this->restockFromLocationName = $fromName !== '' ? $fromName : null;
        $toName = isset($payload['restock_to_location_name']) ? trim((string) $payload['restock_to_location_name']) : '';
        $this->restockToLocationName = $toName !== '' ? $toName : null;
        $sourceKind = isset($payload['restock_source_kind']) ? trim((string) $payload['restock_source_kind']) : '';
        $this->restockSourceKind = $sourceKind !== '' ? $sourceKind : null;
        $destKind = isset($payload['restock_destination_kind']) ? trim((string) $payload['restock_destination_kind']) : '';
        $this->restockDestinationKind = $destKind !== '' ? $destKind : null;
        $this->restockFromQtyBefore = array_key_exists('restock_from_qty_before', $payload) && $payload['restock_from_qty_before'] !== null
            ? max(0, (int) $payload['restock_from_qty_before'])
            : null;
        $this->restockToQtyBefore = array_key_exists('restock_to_qty_before', $payload) && $payload['restock_to_qty_before'] !== null
            ? max(0, (int) $payload['restock_to_qty_before'])
            : null;
    }

    public function handle(
        ShipHeroInventoryService $inventory,
        PutAwayInventoryService $putAway,
        InventoryProductDetailCacheService $detailCache
    ): void {
        $fromLocationId = $this->fromLocationId !== '' ? $this->fromLocationId : $this->fromLocationInput;
        if ($fromLocationId === '') {
            throw ValidationException::withMessages([
                'from_location_id' => ['From location is required.'],
            ]);
        }

        $resolvedFrom = $this->resolveInventoryLocation(
            $inventory,
            $this->sku,
            $this->warehouseId,
            $fromLocationId,
            $this->shipheroCustomerId
        );
        if (is_array($resolvedFrom) && trim((string) ($resolvedFrom['id'] ?? '')) !== '') {
            $fromLocationId = (string) $resolvedFrom['id'];
        }
        $fromLocationName = $this->restockFromLocationName
            ?? (is_array($resolvedFrom) ? trim((string) ($resolvedFrom['name'] ?? '')) : '');
        if ($fromLocationName === '') {
            $fromLocationName = $this->fromLocationInput !== '' ? $this->fromLocationInput : $fromLocationId;
        }

        $toLocationId = $this->toLocationId;
        $resolvedTo = null;
        if ($toLocationId === '') {
            $resolvedTo = $this->resolveInventoryLocation(
                $inventory,
                $this->sku,
                $this->warehouseId,
                $this->toLocationInput,
                $this->shipheroCustomerId
            );
            if (! is_array($resolvedTo)) {
                throw ValidationException::withMessages([
                    'to_location' => ['Location not found in this warehouse.'],
                ]);
            }
            $toLocationId = (string) ($resolvedTo['id'] ?? '');
        } else {
            $resolvedTo = $this->resolveInventoryLocation(
                $inventory,
                $this->sku,
                $this->warehouseId,
                $toLocationId,
                $this->shipheroCustomerId
            );
        }
        $toLocationName = $this->restockToLocationName
            ?? (is_array($resolvedTo) ? trim((string) ($resolvedTo['name'] ?? '')) : '');
        if ($toLocationName === '') {
            $toLocationName = $this->toLocationInput !== '' ? $this->toLocationInput : $toLocationId;
        }

        $inventory->transferLocationQuantity(
            $this->sku,
            $this->warehouseId,
            $fromLocationId,
            $toLocationId,
            $this->quantity,
            $this->reason,
            $this->shipheroCustomerId
        );

        if ($this->clientAccountId !== null) {
            $putAway->syncLocalReceivingAfterTransferFrom(
                $this->clientAccountId,
                $this->sku,
                $this->warehouseId,
                $fromLocationId,
                $this->quantity,
                $this->shipheroCustomerId
            );
            $detailCache->clearForSku($this->clientAccountId, $this->sku);
        }

        $this->applyRestockSnapshotAfterTransfer($fromLocationName, $toLocationName);
    }

    public function failed(Throwable $e): void
    {
        $this->revertRestockStatusOnFailure($e);
    }

    private function applyRestockSnapshotAfterTransfer(string $fromLocationName, string $toLocationName): void
    {
        if ($this->sku === '') {
            return;
        }

        $sourceKind = $this->restockSourceKind ?: 'backstock';
        $destinationKind = $this->restockDestinationKind ?: 'pick';

        try {
            $restock = app(InventoryRestockBetaService::class);
            $applied = $restock->applyTransferToSku($this->sku, [
                'from_location_name' => $fromLocationName,
                'to_location_name' => $toLocationName,
                'quantity' => $this->quantity,
                'source_kind' => $sourceKind,
                'destination_kind' => $destinationKind,
                'next_status' => $this->restockNextStatus,
                'from_qty_before' => $this->restockFromQtyBefore,
                'to_qty_before' => $this->restockToQtyBefore,
            ]);

            if ($applied === null && $this->restockNextStatus !== null) {
                $restock->setSkuStatus($this->sku, $this->restockNextStatus);
            }
        } catch (Throwable $e) {
            Log::warning('inventory.transfer.restock_snapshot_apply_failed', [
                'sku' => $this->sku,
                'next_status' => $this->restockNextStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function revertRestockStatusOnFailure(Throwable $e): void
    {
        // Legacy path: status was written before transfer. New path uses restock_next_status instead.
        if ($this->restockPreviousStatus !== null && $this->sku !== '') {
            try {
                app(InventoryRestockBetaService::class)->setSkuStatus($this->sku, $this->restockPreviousStatus);
            } catch (Throwable $revertError) {
                Log::warning('inventory.transfer.restock_status_revert_failed', [
                    'sku' => $this->sku,
                    'previous_status' => $this->restockPreviousStatus,
                    'error' => $revertError->getMessage(),
                ]);
            }
        }

        if ($this->sku === '') {
            return;
        }

        $message = $e->getMessage() !== ''
            ? $e->getMessage()
            : 'Inventory transfer failed.';

        Cache::put(
            self::RESTOCK_ERROR_CACHE_PREFIX.mb_strtolower($this->sku),
            $message,
            now()->addMinutes(30)
        );

        Log::warning('inventory.transfer.background_failed', [
            'sku' => $this->sku,
            'warehouse_id' => $this->warehouseId,
            'error' => $message,
        ]);
    }

    /**
     * @return array{id:string,name:string,type:?string,pickable:?bool,sellable:?bool}|null
     */
    private function resolveInventoryLocation(
        ShipHeroInventoryService $inventory,
        string $sku,
        string $warehouseId,
        string $locationInput,
        ?string $customerAccountId
    ): ?array {
        $resolved = $inventory->resolveWarehouseLocation($warehouseId, $locationInput, $customerAccountId);
        if (is_array($resolved)) {
            return $resolved;
        }

        $resolved = $inventory->resolveProductWarehouseLocation(
            $sku,
            $warehouseId,
            $locationInput,
            $customerAccountId
        );
        if (is_array($resolved)) {
            return $resolved;
        }

        if (is_string($customerAccountId) && trim($customerAccountId) !== '') {
            $resolved = $inventory->resolveWarehouseLocation($warehouseId, $locationInput, null);
            if (is_array($resolved)) {
                return $resolved;
            }
        }

        return null;
    }
}
