<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\OrderDashboardSection;
use App\Services\ShipHeroCredentialResolver;
use App\Services\ShipHeroOrderQueueIndexService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DiagnoseShipHeroCommand extends Command
{
    protected $signature = 'crm:diagnose-shiphero';

    protected $description = 'Report ShipHero token, queue jobs, sync status, and local index row counts';

    public function handle(ShipHeroCredentialResolver $credentials): int
    {
        $this->info('ShipHero diagnostics');
        $this->line('');

        $this->reportToken($credentials);
        $this->line('');
        $this->reportAccounts();
        $this->line('');
        $this->reportJobs();
        $this->line('');
        $this->reportSyncStatuses();
        $this->line('');
        $this->reportIndexCounts();
        $this->line('');
        $this->reportDashboardSections();

        return self::SUCCESS;
    }

    private function reportToken(ShipHeroCredentialResolver $credentials): void
    {
        $this->comment('Credentials');
        $envToken = config('services.shiphero.refresh_token');
        $hasEnv = is_string($envToken) && trim($envToken) !== '';
        $this->line('SHIPHERO_REFRESH_TOKEN set: '.($hasEnv ? 'yes' : 'no'));

        if (! $hasEnv) {
            return;
        }

        try {
            $credentials->resolveRefreshToken();
            $this->line('Token resolves: yes (source: '.$credentials->credentialSource().')');
        } catch (Throwable $e) {
            $this->warn('Token resolves: no — '.$e->getMessage());
        }
    }

    private function reportAccounts(): void
    {
        $this->comment('Client accounts');

        $withId = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->count();

        $withoutId = ClientAccount::query()
            ->where(function ($q) {
                $q->whereNull('shiphero_customer_account_id')
                    ->orWhere('shiphero_customer_account_id', '=', '');
            })
            ->count();

        $this->line('With shiphero_customer_account_id: '.$withId);
        $this->line('Without shiphero_customer_account_id: '.$withoutId);
    }

    private function reportJobs(): void
    {
        $this->comment('Queue jobs');

        if (! Schema::hasTable('jobs')) {
            $this->warn('jobs table not found (run migrations).');

            return;
        }

        $count = (int) DB::table('jobs')->count();
        $this->line('Pending jobs: '.$count);

        if ($count === 0) {
            return;
        }

        $oldest = DB::table('jobs')->orderBy('id')->value('created_at');
        if ($oldest === null) {
            return;
        }

        try {
            $age = Carbon::parse($oldest)->diffForHumans(now(), true);
            $this->line('Oldest job age: '.$age.' (created '.$oldest.')');
        } catch (Throwable $e) {
            $this->line('Oldest job created_at: '.$oldest);
        }
    }

    private function reportSyncStatuses(): void
    {
        $this->comment('Account sync status');

        $inventoryRunning = ClientAccount::query()
            ->where('inventory_catalog_sync_status', ShipHeroOrderQueueIndexService::SYNC_STATUS_RUNNING)
            ->count();
        $inventoryFailed = ClientAccount::query()
            ->where('inventory_catalog_sync_status', ShipHeroOrderQueueIndexService::SYNC_STATUS_FAILED)
            ->count();

        $ordersRunning = ClientAccount::query()
            ->where('order_queue_sync_status', ShipHeroOrderQueueIndexService::SYNC_STATUS_RUNNING)
            ->count();
        $ordersFailed = ClientAccount::query()
            ->where('order_queue_sync_status', ShipHeroOrderQueueIndexService::SYNC_STATUS_FAILED)
            ->count();

        $this->line('inventory_catalog_sync_status running: '.$inventoryRunning);
        $this->line('inventory_catalog_sync_status failed: '.$inventoryFailed);
        $this->line('order_queue_sync_status running: '.$ordersRunning);
        $this->line('order_queue_sync_status failed: '.$ordersFailed);

        if ($inventoryRunning > 0) {
            $ids = ClientAccount::query()
                ->where('inventory_catalog_sync_status', ShipHeroOrderQueueIndexService::SYNC_STATUS_RUNNING)
                ->orderBy('id')
                ->limit(20)
                ->pluck('id')
                ->all();
            $this->line('  inventory running account ids: '.implode(', ', $ids).($inventoryRunning > 20 ? ' …' : ''));
        }

        if ($ordersRunning > 0) {
            $ids = ClientAccount::query()
                ->where('order_queue_sync_status', ShipHeroOrderQueueIndexService::SYNC_STATUS_RUNNING)
                ->orderBy('id')
                ->limit(20)
                ->pluck('id')
                ->all();
            $this->line('  order queue running account ids: '.implode(', ', $ids).($ordersRunning > 20 ? ' …' : ''));
        }
    }

    private function reportIndexCounts(): void
    {
        $this->comment('Local index tables');

        if (Schema::hasTable('shiphero_inventory_product_index')) {
            $this->line('shiphero_inventory_product_index rows: '.(int) DB::table('shiphero_inventory_product_index')->count());
        } else {
            $this->warn('shiphero_inventory_product_index table not found.');
        }

        if (Schema::hasTable('shiphero_order_queue_index')) {
            $this->line('shiphero_order_queue_index rows: '.(int) DB::table('shiphero_order_queue_index')->count());
        } else {
            $this->warn('shiphero_order_queue_index table not found.');
        }
    }

    private function reportDashboardSections(): void
    {
        $this->comment('Home dashboard sections (order_dashboard_sections)');

        if (! Schema::hasTable('order_dashboard_sections')) {
            $this->warn('order_dashboard_sections table not found.');

            return;
        }

        $sections = OrderDashboardSection::query()
            ->orderBy('section_key')
            ->get(['section_key', 'status', 'refreshed_at', 'total_count']);

        if ($sections->isEmpty()) {
            $this->warn('No dashboard sections found (run orders:refresh-home-dashboard --sync).');

            return;
        }

        foreach ($sections as $section) {
            $refreshed = $section->refreshed_at !== null
                ? $section->refreshed_at->toIso8601String()
                : 'never';
            $this->line(sprintf(
                '  %s: status=%s total=%s refreshed_at=%s',
                $section->section_key,
                $section->status ?? 'unknown',
                (string) ($section->total_count ?? 0),
                $refreshed
            ));
        }
    }
}
