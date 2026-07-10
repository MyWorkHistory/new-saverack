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
        {--from-index : Refresh from local index only (no ShipHero API; skips live totals)}';

    protected $description = 'Refresh admin Home dashboard order/ASN snapshot sections (default: live ShipHero API)';

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

        if ($this->option('sync')) {
            if ($section === 'all') {
                if ($fromIndex) {
                    $this->info('Refreshing primary totals from local index…');
                    $snapshots->refreshPrimaryTotals(false);
                } else {
                    $this->info('Refreshing from live ShipHero API (one account every ~1.2s, expect ~5–10 min)…');
                    $snapshots->refreshPrimaryTotals(true);
                }
                $this->info('Refreshing ASN pending…');
                $snapshots->refreshSection(OrderDashboardSection::KEY_ASN_PENDING);
                $this->info('Home dashboard refresh complete.');

                return self::SUCCESS;
            }

            foreach ($keys as $key) {
                $this->info('Refreshing '.$key.'…');
                if ($fromIndex && $key !== OrderDashboardSection::KEY_ASN_PENDING) {
                    $snapshots->refreshSectionFromIndex($key);
                } else {
                    $snapshots->refreshSection($key, $fromIndex);
                }
            }
            $this->info('Home dashboard refresh complete.');

            return self::SUCCESS;
        }

        if ($section === 'all') {
            if ($fromIndex) {
                foreach (OrderDashboardSection::ALL_KEYS as $key) {
                    RefreshOrderDashboardSectionJob::dispatch($key, true);
                    $this->info('Queued refresh for '.$key.' (from index)');
                }
            } else {
                $snapshots->dispatchPrimaryTotalsRefresh();
                RefreshOrderDashboardSectionJob::dispatch(OrderDashboardSection::KEY_ASN_PENDING);
                $this->info('Queued live primary totals refresh (~5–10 min on database-long queue) and ASN pending.');
            }

            if ($fromIndex) {
                Cache::put('shiphero:schedule:last_run:orders_refresh_home_dashboard_index', now()->toIso8601String(), now()->addDays(7));
            }

            return self::SUCCESS;
        }

        foreach ($keys as $key) {
            RefreshOrderDashboardSectionJob::dispatch($key, $fromIndex);
            $this->info('Queued refresh for '.$key.($fromIndex ? ' (from index)' : ' (live)'));
        }

        if ($fromIndex) {
            Cache::put('shiphero:schedule:last_run:orders_refresh_home_dashboard_index', now()->toIso8601String(), now()->addDays(7));
        }

        return self::SUCCESS;
    }
}
