<?php

namespace App\Console\Commands;

use App\Services\PortalQueueCountsService;
use Illuminate\Console\Command;

class RefreshPendingPortalQueueCountsCommand extends Command
{
    protected $signature = 'portal:refresh-pending-queue-counts';

    protected $description = 'Process portal dashboard queue-count rebuilds queued when shell exec is unavailable';

    public function handle(PortalQueueCountsService $queueCounts): int
    {
        $count = $queueCounts->processPendingRebuilds();
        $this->info('Refreshed queue counts for '.$count.' account(s).');

        return 0;
    }
}
