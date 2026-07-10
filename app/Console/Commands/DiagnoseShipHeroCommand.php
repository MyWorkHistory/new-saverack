<?php

namespace App\Console\Commands;

use App\Jobs\BackfillOrderQueueIndexChunkJob;
use App\Models\ClientAccount;
use App\Models\OrderDashboardSection;
use App\Models\ShipHeroOrderQueueIndex;
use App\Models\ShipHeroWebhookEvent;
use App\Services\PortalQueueCountsService;
use App\Services\ShipHeroCredentialResolver;
use App\Services\ShipHeroDashboardMetricsService;
use App\Services\ShipHeroInventoryService;
use App\Services\ShipHeroOrderQueueIndexService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DiagnoseShipHeroCommand extends Command
{
    protected $signature = 'crm:diagnose-shiphero';

    protected $description = 'Report ShipHero token, queue jobs, sync status, and local index row counts';

    public function handle(
        ShipHeroCredentialResolver $credentials,
        ShipHeroInventoryService $inventory,
        ShipHeroDashboardMetricsService $metrics,
        ShipHeroOrderQueueIndexService $orderIndex
    ): int {
        $this->info('ShipHero diagnostics');
        $this->line('');

        $this->reportToken($credentials);
        $this->line('');
        $this->reportQueueConfig();
        $this->line('');
        $this->reportScheduledSyncHints();
        $this->line('');
        $this->reportAccounts();
        $this->line('');
        $this->reportWebhooks();
        $this->line('');
        $this->reportJobs();
        $this->line('');
        $this->reportSyncStatuses();
        $this->line('');
        $this->reportIndexCounts();
        $this->line('');
        $this->reportOrderIndexHealth($metrics, $orderIndex);
        $this->line('');
        $this->reportInventoryRevisions($inventory);
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

    private function reportQueueConfig(): void
    {
        $this->comment('Queue configuration');

        $connection = (string) config('queue.default', 'sync');
        $this->line('QUEUE_CONNECTION (config): '.$connection);

        if ($connection === 'sync') {
            $this->warn('QUEUE_CONNECTION=sync — background jobs run inline or not at all. Set QUEUE_CONNECTION=database in .env and run a queue worker.');
        }

        if (Schema::hasTable('failed_jobs')) {
            $failed = (int) DB::table('failed_jobs')->count();
            $this->line('failed_jobs rows: '.$failed);
            if ($failed > 0) {
                $latest = DB::table('failed_jobs')->orderByDesc('id')->value('failed_at');
                $this->warn('Latest failed job at: '.($latest ?? 'unknown').' — run: php artisan queue:failed');
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function scheduledSyncCacheKeys(): array
    {
        return [
            'orders:sync-recent-updates' => 'shiphero:schedule:last_run:orders_sync_recent_updates',
            'orders:refresh-home-dashboard --from-index' => 'shiphero:schedule:last_run:orders_refresh_home_dashboard_index',
            'inventory:sync-catalog-incremental' => 'shiphero:schedule:last_run:inventory_sync_catalog_incremental',
        ];
    }

    private function reportScheduledSyncHints(): void
    {
        $this->comment('Scheduled sync (last successful run)');

        $hasIncrementalCmd = class_exists(SyncInventoryCatalogIncrementalCommand::class);
        $this->line('inventory:sync-catalog-incremental command present: '.($hasIncrementalCmd ? 'yes' : 'no (deploy latest code)'));

        foreach ($this->scheduledSyncCacheKeys() as $label => $cacheKey) {
            $ranAt = Cache::get($cacheKey);
            if ($ranAt === null) {
                $this->line('  '.$label.': never (or cron not running this command)');
                continue;
            }
            try {
                $this->line('  '.$label.': '.Carbon::parse($ranAt)->toIso8601String());
            } catch (Throwable $e) {
                $this->line('  '.$label.': '.$ranAt);
            }
        }

        $this->line('');
        $this->line('Lightweight schedule does NOT full-rebuild empty order/inventory indexes.');
        $this->line('If index row counts are 0, run once: php artisan crm:warm-shiphero-data');
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

    private function reportWebhooks(): void
    {
        $this->comment('Webhooks');

        $secret = trim((string) config('services.shiphero.webhook_secret', ''));
        $url = trim((string) config('services.shiphero.webhook_url', ''));
        $this->line('SHIPHERO_WEBHOOK_SECRET set: '.($secret !== '' ? 'yes' : 'no'));
        $this->line('SHIPHERO_WEBHOOK_URL: '.($url !== '' ? $url : '(not set)'));

        if (! Schema::hasTable('shiphero_webhook_events')) {
            $this->warn('shiphero_webhook_events table not found.');

            return;
        }

        $pending = (int) ShipHeroWebhookEvent::query()->whereNull('processed_at')->count();
        $this->line('Unprocessed webhook events: '.$pending);

        if ($pending > 0) {
            $oldest = ShipHeroWebhookEvent::query()
                ->whereNull('processed_at')
                ->orderBy('id')
                ->first(['id', 'event_type', 'created_at', 'processing_error']);
            if ($oldest !== null) {
                $this->warn('Oldest pending: #'.$oldest->id.' '.$oldest->event_type.' (created '.$oldest->created_at.')');
                if (is_string($oldest->processing_error) && trim($oldest->processing_error) !== '') {
                    $this->warn('  Error: '.$oldest->processing_error);
                }
            }
        }

        $recentErrors = (int) ShipHeroWebhookEvent::query()
            ->whereNotNull('processing_error')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        if ($recentErrors > 0) {
            $this->warn('Webhook events with errors (24h): '.$recentErrors);
        }

        if (Schema::hasTable('jobs')) {
            $webhookJobs = (int) DB::table('jobs')
                ->where('payload', 'like', '%ProcessShipHeroOrderWebhookJob%')
                ->count();
            if ($webhookJobs > 0) {
                $this->warn('Pending ProcessShipHeroOrderWebhookJob in jobs table: '.$webhookJobs);
                $this->line('  Run: php artisan queue:work '.config('queue.default', 'database').' --stop-when-empty');
            }
        }

        $lastProcessed = ShipHeroWebhookEvent::query()
            ->whereNotNull('processed_at')
            ->orderByDesc('processed_at')
            ->value('processed_at');
        if ($lastProcessed !== null) {
            try {
                $this->line('Last processed at: '.Carbon::parse($lastProcessed)->toIso8601String());
            } catch (Throwable $e) {
                $this->line('Last processed at: '.$lastProcessed);
            }
        } else {
            $this->line('Last processed at: never');
        }
    }

    private function reportInventoryRevisions(ShipHeroInventoryService $inventory): void
    {
        $this->comment('Inventory catalog revisions (sample)');

        $accounts = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('id')
            ->limit(5)
            ->get(['id', 'company_name']);

        if ($accounts->isEmpty()) {
            $this->line('No linked accounts.');

            return;
        }

        foreach ($accounts as $account) {
            $revision = $inventory->getCatalogRevision((int) $account->id);
            $this->line('  #'.$account->id.' '.$account->company_name.': revision '.$revision);
        }
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

            $stale = ClientAccount::query()
                ->where('order_queue_sync_status', ShipHeroOrderQueueIndexService::SYNC_STATUS_RUNNING)
                ->where('order_queue_sync_started_at', '<', now()->subMinutes(75))
                ->count();
            if ($stale > 0) {
                $this->warn('  '.$stale.' account(s) stuck order_queue_sync_status=running >75m (reset or re-run sync).');
            }
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
            $total = (int) DB::table('shiphero_order_queue_index')->count();
            $this->line('shiphero_order_queue_index rows (total): '.$total);
            $this->reportOrderQueueIndexByKind();
        } else {
            $this->warn('shiphero_order_queue_index table not found.');
        }
    }

    private function reportOrderQueueIndexByKind(): void
    {
        $this->line('  shiphero_order_queue_index by queue_kind:');

        $counts = DB::table('shiphero_order_queue_index')
            ->select('queue_kind', DB::raw('count(*) as aggregate'))
            ->groupBy('queue_kind')
            ->pluck('aggregate', 'queue_kind');

        foreach (ShipHeroOrderQueueIndex::QUEUE_KINDS as $kind) {
            $this->line('    '.$kind.': '.(int) ($counts[$kind] ?? 0));
        }

        $onHoldOnly = (int) ($counts[ShipHeroOrderQueueIndex::KIND_ON_HOLD] ?? 0) > 0
            && (int) ($counts[ShipHeroOrderQueueIndex::KIND_AWAITING] ?? 0) === 0
            && (int) ($counts[ShipHeroOrderQueueIndex::KIND_SHIPPED] ?? 0) === 0
            && (int) ($counts[ShipHeroOrderQueueIndex::KIND_BACKORDER] ?? 0) === 0;

        if ($onHoldOnly) {
            $this->warn('  Only on_hold has rows — list pages for Ready to Ship / Shipped / Backorder will be empty until you run orders:sync-queue-index --sync.');
        }
    }

    private function reportOrderIndexHealth(
        ShipHeroDashboardMetricsService $metrics,
        ShipHeroOrderQueueIndexService $orderIndex
    ): void {
        $this->comment('Order index health');

        if (! Schema::hasTable('shiphero_order_queue_index')) {
            $this->warn('shiphero_order_queue_index table not found.');

            return;
        }

        $timezone = PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE;
        $todayStart = Carbon::now($timezone)->startOfDay();
        $todayEnd = Carbon::now($timezone)->endOfDay();
        $rtsFrom = Carbon::parse(PortalQueueCountsService::RTS_DASHBOARD_ORDER_FROM, $timezone)->startOfDay();

        $shippedTodayOrders = (int) DB::table('shiphero_order_queue_index')
            ->where('queue_kind', ShipHeroOrderQueueIndex::KIND_SHIPPED)
            ->where('ship_date', '>=', $todayStart)
            ->where('ship_date', '<=', $todayEnd)
            ->count();

        $shippedTodayRows = DB::table('shiphero_order_queue_index')
            ->where('queue_kind', ShipHeroOrderQueueIndex::KIND_SHIPPED)
            ->where('ship_date', '>=', $todayStart)
            ->where('ship_date', '<=', $todayEnd)
            ->get(['list_payload']);

        $shippedTodayLabels = 0;
        foreach ($shippedTodayRows as $row) {
            $payload = json_decode((string) ($row->list_payload ?? '{}'), true);
            if (! is_array($payload)) {
                $payload = [];
            }
            $shippedTodayLabels += max(1, (int) ($payload['shipped_label_count'] ?? 1));
        }

        $awaitingTotal = (int) DB::table('shiphero_order_queue_index')
            ->where('queue_kind', ShipHeroOrderQueueIndex::KIND_AWAITING)
            ->count();

        $awaitingSinceRts = (int) DB::table('shiphero_order_queue_index')
            ->where('queue_kind', ShipHeroOrderQueueIndex::KIND_AWAITING)
            ->where('order_date', '>=', $rtsFrom)
            ->count();

        $this->line('  shipped today (index orders): '.$shippedTodayOrders);
        $this->line('  shipped today (sum shipped_label_count): '.$shippedTodayLabels);
        $this->line('  awaiting total: '.$awaitingTotal);
        $this->line('  awaiting order_date >= '.PortalQueueCountsService::RTS_DASHBOARD_ORDER_FROM.': '.$awaitingSinceRts);

        if (Schema::hasTable('jobs')) {
            $needle = class_basename(BackfillOrderQueueIndexChunkJob::class);
            $pendingBackfill = (int) DB::table('jobs')
                ->where('payload', 'like', '%'.$needle.'%')
                ->count();
            $this->line('  pending BackfillOrderQueueIndexChunkJob: '.$pendingBackfill);
        }

        $this->line('  top accounts shipped today (labels):');
        $shippedByAccount = DB::table('shiphero_order_queue_index')
            ->join('client_accounts', 'client_accounts.id', '=', 'shiphero_order_queue_index.client_account_id')
            ->where('shiphero_order_queue_index.queue_kind', ShipHeroOrderQueueIndex::KIND_SHIPPED)
            ->where('shiphero_order_queue_index.ship_date', '>=', $todayStart)
            ->where('shiphero_order_queue_index.ship_date', '<=', $todayEnd)
            ->select(
                'shiphero_order_queue_index.client_account_id',
                'client_accounts.company_name',
                DB::raw('count(*) as order_count'),
                DB::raw("COALESCE(SUM(GREATEST(1, CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(shiphero_order_queue_index.list_payload, '$.shipped_label_count')), '1') AS UNSIGNED))), 0) as label_count")
            )
            ->groupBy('shiphero_order_queue_index.client_account_id', 'client_accounts.company_name')
            ->orderByDesc('label_count')
            ->limit(5)
            ->get();

        if ($shippedByAccount->isEmpty()) {
            $this->line('    (none)');
        } else {
            foreach ($shippedByAccount as $row) {
                $this->line(sprintf(
                    '    #%d %s: %d orders, %d labels',
                    (int) $row->client_account_id,
                    (string) $row->company_name,
                    (int) $row->order_count,
                    (int) $row->label_count
                ));
            }
        }

        $this->line('  top accounts awaiting (all):');
        $awaitingByAccount = DB::table('shiphero_order_queue_index')
            ->join('client_accounts', 'client_accounts.id', '=', 'shiphero_order_queue_index.client_account_id')
            ->where('shiphero_order_queue_index.queue_kind', ShipHeroOrderQueueIndex::KIND_AWAITING)
            ->select(
                'shiphero_order_queue_index.client_account_id',
                'client_accounts.company_name',
                DB::raw('count(*) as order_count')
            )
            ->groupBy('shiphero_order_queue_index.client_account_id', 'client_accounts.company_name')
            ->orderByDesc('order_count')
            ->limit(5)
            ->get();

        if ($awaitingByAccount->isEmpty()) {
            $this->line('    (none)');
        } else {
            foreach ($awaitingByAccount as $row) {
                $this->line(sprintf(
                    '    #%d %s: %d',
                    (int) $row->client_account_id,
                    (string) $row->company_name,
                    (int) $row->order_count
                ));
            }
        }

        $this->line('');
        $this->comment('  Live API vs index (dashboard totals)');

        try {
            $liveRts = (int) ($metrics->aggregateReadyToShip(false)['total_count'] ?? 0);
            $liveOnHold = (int) ($metrics->aggregateOnHoldToday(false)['total_count'] ?? 0);
            $liveShipped = (int) ($metrics->aggregateShippedToday(false)['total_count'] ?? 0);
        } catch (Throwable $e) {
            $this->warn('  Could not fetch live ShipHero totals: '.$e->getMessage());
            $liveRts = 0;
            $liveOnHold = 0;
            $liveShipped = 0;
        }

        $indexRts = $orderIndex->aggregateReadyToShipFromIndex();
        $indexOnHold = $orderIndex->aggregateOnHoldTodayFromIndex();
        $indexShipped = $orderIndex->aggregateShippedTodayFromIndex();

        $rows = [
            ['Ready to ship (May 1+)', $liveRts, $indexRts],
            ['On-hold (order date today)', $liveOnHold, $indexOnHold],
            ['Shipped today (labels)', $liveShipped, $indexShipped],
        ];

        $this->line(sprintf('  %-28s %8s %8s %8s', 'Metric', 'Live', 'Index', 'Delta'));
        foreach ($rows as [$label, $live, $index]) {
            $delta = $live - $index;
            $this->line(sprintf('  %-28s %8d %8d %+8d', $label, $live, $index, $delta));
        }

        $needsWarm = $indexShipped < $liveShipped
            || $indexRts < $liveRts
            || $indexOnHold < $liveOnHold;

        if ($needsWarm) {
            $this->warn('  Index is behind live ShipHero — run: php artisan crm:warm-shiphero-data');
            $this->warn('  Then refresh dashboard: php artisan orders:refresh-home-dashboard --sync');
        } else {
            $this->line('  Index matches live API within counts above.');
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
