<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Services\PortalQueueCountsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RefreshPortalQueueCountsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $clientAccountId;

    public $timeout = 300;

    public function __construct(int $clientAccountId)
    {
        $this->clientAccountId = $clientAccountId;
    }

    public function handle(PortalQueueCountsService $queueCounts): void
    {
        $lockKey = 'orders:queue_counts:lock:'.$this->clientAccountId;
        if (! Cache::add($lockKey, 1, now()->addMinutes(5))) {
            return;
        }

        try {
            $account = ClientAccount::query()->find($this->clientAccountId);
            if ($account === null) {
                return;
            }
            $sid = trim((string) ($account->shiphero_customer_account_id ?? ''));
            if ($sid === '') {
                return;
            }
            $context = $queueCounts->contextForAccount($account);
            $queueCounts->buildAllQueues($context);
        } catch (Throwable $e) {
            report($e);
            throw $e;
        } finally {
            Cache::forget($lockKey);
        }
    }
}
