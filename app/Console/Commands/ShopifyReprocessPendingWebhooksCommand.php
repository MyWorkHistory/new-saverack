<?php

namespace App\Console\Commands;

use App\Jobs\ProcessShopifyWebhookJob;
use App\Models\ShopifyWebhookEvent;
use App\Services\ShopifyBootstrapImportService;
use App\Services\ShopifyClient;
use App\Services\ShopifyOrderSyncService;
use App\Services\ShopifyProductSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ShopifyReprocessPendingWebhooksCommand extends Command
{
    public const LAST_RUN_CACHE_KEY = 'shopify:schedule:last_run:reprocess_pending_webhooks';

    protected $signature = 'shopify:reprocess-pending-webhooks
        {--limit=50 : Max events}
        {--min-age-seconds=30 : Only older than this}
        {--queue : Re-dispatch to queue instead of processing inline}';

    protected $description = 'Process stuck Shopify webhook events (inline by default so cron works without a queue worker)';

    public function handle(
        ShopifyProductSyncService $products,
        ShopifyOrderSyncService $orders,
        ShopifyBootstrapImportService $bootstrap,
        ShopifyClient $client
    ): int {
        if (! Schema::hasTable('shopify_webhook_events')) {
            $this->warn('shopify_webhook_events missing.');

            return self::SUCCESS;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $minAge = max(0, min(3600, (int) $this->option('min-age-seconds')));
        $cutoff = now()->subSeconds($minAge);

        $ids = ShopifyWebhookEvent::query()
            ->whereNull('processed_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static function ($id) {
                return (int) $id;
            })
            ->all();

        if ($ids === []) {
            $this->info('No pending Shopify webhook events.');
            Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addDays(7));

            return self::SUCCESS;
        }

        $useQueue = (bool) $this->option('queue');
        $ok = 0;
        $fail = 0;

        foreach ($ids as $id) {
            if ($useQueue) {
                ProcessShopifyWebhookJob::dispatch($id);
                $ok++;
                continue;
            }

            try {
                (new ProcessShopifyWebhookJob($id))->handle($products, $orders, $bootstrap, $client);
                $event = ShopifyWebhookEvent::query()->find($id);
                if ($event && $event->processed_at !== null && $event->processing_error === null) {
                    $ok++;
                } elseif ($event && $event->processed_at !== null) {
                    $this->warn('  #'.$id.' processed with note: '.$event->processing_error);
                    $ok++;
                } else {
                    $fail++;
                    $this->error('  #'.$id.' still pending after handle.');
                }
            } catch (Throwable $e) {
                $fail++;
                $this->error('  #'.$id.' '.$e->getMessage());
            }
        }

        $this->info(
            ($useQueue ? 'Redispatched ' : 'Processed ')
            .count($ids).' Shopify webhook event(s)'
            .' (ok='.$ok.', fail='.$fail.').'
        );
        Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addDays(7));

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
