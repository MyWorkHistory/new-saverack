<?php

namespace App\Jobs;

use App\Models\ClientAccountReturnLine;
use App\Models\ReturnBin;
use App\Services\InventoryProductDetailCacheService;
use App\Services\ReturnBinService;
use App\Services\ShipHeroInventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Background ShipHero transfer after CRM Return Cart qty was already decremented in the HTTP request.
 */
class TransferReturnBinItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;

    public $tries = 1;

    /** @var int */
    public $returnBinId;

    /** @var string */
    public $sku;

    /** @var int */
    public $clientAccountId;

    /** @var string */
    public $warehouseId;

    /** @var string */
    public $fromLocationId;

    /** @var string */
    public $toLocationId;

    /** @var int */
    public $quantity;

    /** @var string */
    public $reason;

    /** @var string|null */
    public $shipheroCustomerId;

    /**
     * @param  array{
     *   return_bin_id: int,
     *   sku: string,
     *   client_account_id: int,
     *   warehouse_id: string,
     *   from_location_id: string,
     *   to_location_id: string,
     *   quantity: int,
     *   reason: string,
     *   shiphero_customer_id?: string|null
     * }  $payload
     */
    public function __construct(array $payload)
    {
        $this->returnBinId = (int) ($payload['return_bin_id'] ?? 0);
        $this->sku = trim((string) ($payload['sku'] ?? ''));
        $this->clientAccountId = (int) ($payload['client_account_id'] ?? 0);
        $this->warehouseId = trim((string) ($payload['warehouse_id'] ?? ''));
        $this->fromLocationId = trim((string) ($payload['from_location_id'] ?? ''));
        $this->toLocationId = trim((string) ($payload['to_location_id'] ?? ''));
        $this->quantity = (int) ($payload['quantity'] ?? 0);
        $this->reason = (string) ($payload['reason'] ?? 'Return Restock');
        $customer = $payload['shiphero_customer_id'] ?? null;
        $this->shipheroCustomerId = is_string($customer) && trim($customer) !== '' ? trim($customer) : null;
        $this->onConnection((string) config('queue.default', 'database'));
    }

    public function handle(
        ShipHeroInventoryService $inventory,
        InventoryProductDetailCacheService $detailCache
    ): void {
        if (
            $this->returnBinId <= 0
            || $this->sku === ''
            || $this->warehouseId === ''
            || $this->fromLocationId === ''
            || $this->toLocationId === ''
            || $this->quantity <= 0
        ) {
            return;
        }

        $inventory->transferLocationQuantity(
            $this->sku,
            $this->warehouseId,
            $this->fromLocationId,
            $this->toLocationId,
            $this->quantity,
            $this->reason,
            $this->shipheroCustomerId
        );

        if ($this->clientAccountId > 0) {
            $detailCache->clearForSku($this->clientAccountId, $this->sku);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('returns.bin.transfer_background_failed', [
            'return_bin_id' => $this->returnBinId,
            'sku' => $this->sku,
            'client_account_id' => $this->clientAccountId,
            'quantity' => $this->quantity,
            'error' => $e->getMessage(),
        ]);

        $this->restoreCrmQty();

        $bin = ReturnBin::query()->find($this->returnBinId);
        if ($bin instanceof ReturnBin) {
            try {
                app(ReturnBinService::class)->adjustCachedBinItemQty(
                    $bin,
                    $this->sku,
                    $this->clientAccountId,
                    $this->quantity
                );
            } catch (Throwable $cacheError) {
                Log::warning('returns.bin.transfer_cache_restore_failed', [
                    'sku' => $this->sku,
                    'error' => $cacheError->getMessage(),
                ]);
            }
        }
    }

    private function restoreCrmQty(): void
    {
        if ($this->returnBinId <= 0 || $this->sku === '' || $this->clientAccountId <= 0 || $this->quantity <= 0) {
            return;
        }

        try {
            DB::transaction(function () {
                $remaining = $this->quantity;
                $lines = ClientAccountReturnLine::query()
                    ->where('sku', $this->sku)
                    ->where(function ($query) {
                        $query->where('return_bin_id', $this->returnBinId)
                            ->orWhereNull('return_bin_id');
                    })
                    ->whereHas('clientAccountReturn', function ($query) {
                        $query->where('client_account_id', $this->clientAccountId);
                    })
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($lines as $line) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $current = max(0, (int) ($line->return_bin_remaining_qty ?? 0));
                    $add = $remaining;
                    $line->return_bin_id = $this->returnBinId;
                    $line->return_bin_remaining_qty = $current + $add;
                    $line->save();
                    $remaining -= $add;
                }

                if ($remaining > 0) {
                    Log::warning('returns.bin.transfer_crm_restore_incomplete', [
                        'sku' => $this->sku,
                        'unrestored' => $remaining,
                    ]);
                }
            });
        } catch (Throwable $e) {
            Log::error('returns.bin.transfer_crm_restore_failed', [
                'sku' => $this->sku,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
