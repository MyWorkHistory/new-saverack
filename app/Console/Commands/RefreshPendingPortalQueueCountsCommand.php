<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RefreshPendingPortalQueueCountsCommand extends Command
{
    protected $signature = 'portal:refresh-pending-queue-counts';

    protected $description = 'Deprecated — use portal:refresh-queue-counts {client_account_id} or per-queue API refresh';

    public function handle(): int
    {
        $this->line('No pending queue. Run: php artisan portal:refresh-queue-counts {client_account_id}');

        return 0;
    }
}
