<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Services\PortalQueueCountsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RefreshPortalQueueCountsCommand extends Command
{
    protected $signature = 'portal:refresh-queue-counts {client_account_id : Client account id}';

    protected $description = 'Rebuild cached portal dashboard order queue counts from ShipHero (background job)';

    public function handle(PortalQueueCountsService $queueCounts): int
    {
        $accountId = (int) $this->argument('client_account_id');
        $lockKey = 'orders:queue_counts:lock:'.$accountId;

        $account = ClientAccount::query()->find($accountId);
        if ($account === null) {
            $this->error('Client account not found.');

            return 1;
        }

        $sid = trim((string) ($account->shiphero_customer_account_id ?? ''));
        if ($sid === '') {
            $this->warn('ShipHero not configured for this account.');

            return 0;
        }

        if (! Cache::add($lockKey, 1, now()->addMinutes(5))) {
            $this->line('Rebuild already in progress.');

            return 0;
        }

        try {
            $context = $queueCounts->contextForAccount($account);
            $queueCounts->buildAllQueues($context);
            $this->info('Queue counts cached for account #'.$accountId);
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return 1;
        } finally {
            Cache::forget($lockKey);
        }

        return 0;
    }
}
