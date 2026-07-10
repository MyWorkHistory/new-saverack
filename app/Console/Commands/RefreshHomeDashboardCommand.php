<?php

namespace App\Console\Commands;

use App\Jobs\RefreshOrderDashboardSectionJob;
use App\Models\OrderDashboardSection;
use App\Services\OrderDashboardSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RefreshHomeDashboardCommand extends Command
{
    protected $signature = 'orders:refresh-home-dashboard
        {--section=all : Section key or all}
        {--sync : Run inline instead of queueing}
        {--from-index : Refresh from local index only (no blocking ShipHero API in scheduler)}
        {--live : Force live ShipHero API for shipped (uses API credits; default uses index when populated)}';

    protected $description = 'Refresh admin Home dashboard order/ASN snapshot sections (run with --sync after deploy to warm snapshots)';

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

        $fromIndex = (bool) $this->option('from-index');
        $forceLive = (bool) $this->option('live');

        if ($this->option('sync')) {
            foreach ($keys as $key) {
                $this->info('Refreshing '.$key.'…');
                if ($fromIndex) {
                    $snapshots->refreshSectionFromIndex($key);
                } else {
                    $snapshots->refreshSection($key, $forceLive);
                }
            }
            $this->info('Home dashboard refresh complete.');

            return self::SUCCESS;
        }

        foreach ($keys as $key) {
            RefreshOrderDashboardSectionJob::dispatch($key, $fromIndex);
            $this->info('Queued refresh for '.$key.($fromIndex ? ' (from index)' : ''));
        }

        if ($fromIndex) {
            Cache::put('shiphero:schedule:last_run:orders_refresh_home_dashboard_index', now()->toIso8601String(), now()->addDays(7));
        }

        return self::SUCCESS;
    }
}
