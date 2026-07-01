<?php

namespace App\Console\Commands;

use App\Jobs\RefreshOrderDashboardSectionJob;
use App\Models\OrderDashboardSection;
use App\Services\OrderDashboardSnapshotService;
use Illuminate\Console\Command;

class RefreshHomeDashboardCommand extends Command
{
    protected $signature = 'orders:refresh-home-dashboard {--section=all : Section key or all} {--sync : Run inline instead of queueing}';

    protected $description = 'Refresh admin Home dashboard order/ASN snapshot sections';

    public function handle(OrderDashboardSnapshotService $snapshots): int
    {
        $section = strtolower(trim((string) $this->option('section')));
        if ($section === '') {
            $section = 'all';
        }

        $keys = $section === 'all'
            ? OrderDashboardSection::ALL_KEYS
            : [$section];

        foreach ($keys as $key) {
            if (! in_array($key, OrderDashboardSection::ALL_KEYS, true)) {
                $this->error('Invalid section: '.$key);

                return self::FAILURE;
            }
        }

        if ($this->option('sync')) {
            foreach ($keys as $key) {
                $this->info('Refreshing '.$key.'…');
                $snapshots->refreshSection($key);
            }
            $this->info('Home dashboard refresh complete.');

            return self::SUCCESS;
        }

        foreach ($keys as $key) {
            RefreshOrderDashboardSectionJob::dispatch($key);
            $this->info('Queued refresh for '.$key);
        }

        return self::SUCCESS;
    }
}
