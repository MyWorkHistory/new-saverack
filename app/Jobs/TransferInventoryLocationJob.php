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
     *   restock_previous_status?: string|null
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

        $toLocationId = $this->toLocationId;
        if ($toLocationId === '') {
            $resolved = $this->resolveInventoryLocation(
                $inventory,
                $this->sku,
                $this->warehouseId,
                $this->toLocationInput,
                $this->shipheroCustomerId
            );
            if (! is_array($resolved)) {
                throw ValidationException::withMessages([
                    'to_location' => ['Location not found in this warehouse.'],
                ]);
            }
            $toLocationId = (string) ($resolved['id'] ?? '');
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
    }

    public function failed(Throwable $e): void
    {
        $this->revertRestockStatusOnFailure($e);
    }

    private function revertRestockStatusOnFailure(Throwable $e): void
    {
        if ($this->restockPreviousStatus === null || $this->sku === '') {
            return;
        }

        $message = $e->getMessage() !== ''
            ? $e->getMessage()
            : 'Inventory transfer failed.';

        try {
            app(InventoryRestockBetaService::class)->setSkuStatus($this->sku, $this->restockPreviousStatus);
        } catch (Throwable $revertError) {
            Log::warning('inventory.transfer.restock_status_revert_failed', [
                'sku' => $this->sku,
                'previous_status' => $this->restockPreviousStatus,
                'error' => $revertError->getMessage(),
            ]);
        }

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
