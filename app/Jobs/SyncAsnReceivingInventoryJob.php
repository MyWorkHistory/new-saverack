<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Models\ClientAccountAsnLine;
use App\Services\AsnReceivingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ShipHero Receiving inventory sync after CRM ASN accepted qty was already updated in the HTTP request.
 */
class SyncAsnReceivingInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;

    public $tries = 3;

    /** @var int */
    public $clientAccountId;

    /** @var int */
    public $asnLineId;

    /** @var string */
    public $mode;

    /** @var int */
    public $quantity;

    /** @var string */
    public $reason;

    /**
     * @param  array{
     *   client_account_id: int,
     *   asn_line_id: int,
     *   mode: 'increment'|'absolute',
     *   quantity: int,
     *   reason: string
     * }  $payload
     */
    public function __construct(array $payload)
    {
        $this->clientAccountId = (int) ($payload['client_account_id'] ?? 0);
        $this->asnLineId = (int) ($payload['asn_line_id'] ?? 0);
        $this->mode = (string) ($payload['mode'] ?? 'increment');
        $this->quantity = (int) ($payload['quantity'] ?? 0);
        $this->reason = (string) ($payload['reason'] ?? 'ASN receiving');
        $this->onConnection((string) config('queue.default', 'database'));
    }

    public function handle(AsnReceivingService $receiving): void
    {
        if ($this->clientAccountId <= 0 || $this->asnLineId <= 0 || $this->quantity < 0) {
            return;
        }
        if ($this->mode === 'increment' && $this->quantity <= 0) {
            return;
        }

        $account = ClientAccount::query()->find($this->clientAccountId);
        $line = ClientAccountAsnLine::query()->find($this->asnLineId);
        if ($account === null || $line === null) {
            return;
        }

        $sku = trim((string) $line->sku);
        if ($sku === '') {
            return;
        }

        if ($this->mode === 'absolute') {
            $slice = $receiving->setReceivingInventoryAbsolute($account, $sku, $this->quantity, $this->reason);
        } else {
            $slice = $receiving->incrementReceivingInventory($account, $sku, $this->quantity, $this->reason);
        }

        $receiving->applyPutAwayReceivingSlice($account, $line, $slice);
    }

    public function failed(Throwable $e): void
    {
        Log::warning('asn.receiving.shiphero_sync_failed', [
            'client_account_id' => $this->clientAccountId,
            'asn_line_id' => $this->asnLineId,
            'mode' => $this->mode,
            'quantity' => $this->quantity,
            'error' => $e->getMessage(),
        ]);
    }
}
