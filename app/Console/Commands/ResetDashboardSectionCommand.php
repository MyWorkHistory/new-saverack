<?php

namespace App\Console\Commands;

use App\Models\OrderDashboardSection;
use App\Services\OrderDashboardSnapshotService;
use Illuminate\Console\Command;

class ResetDashboardSectionCommand extends Command
{
    protected $signature = 'orders:reset-dashboard-section
        {section=primary : Section key, or "primary" for ready_to_ship + shipped + on_hold}';

    protected $description = 'Clear a Home dashboard section to zero (use before per-account import)';

    public function handle(OrderDashboardSnapshotService $snapshots): int
    {
        $section = strtolower(trim((string) $this->argument('section')));
        if ($section === '' || $section === 'primary') {
            $snapshots->resetPrimaryDashboardSections();
            $this->info('Cleared primary dashboard sections: ready_to_ship, shipped, on_hold.');

            return self::SUCCESS;
        }

        if (! in_array($section, OrderDashboardSection::ALL_KEYS, true)) {
            $this->error('Invalid section: '.$section);

            return self::FAILURE;
        }

        $snapshots->resetDashboardSection($section);
        $this->info('Cleared section: '.$section);

        return self::SUCCESS;
    }
}
