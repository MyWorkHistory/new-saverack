<?php

namespace App\Console\Commands;

use App\Jobs\ProcessShopifyWebhookJob;
use App\Models\ShopifyWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ShopifyReprocessPendingWebhooksCommand extends Command
{
    public const LAST_RUN_CACHE_KEY = 'shopify:schedule:last_run:reprocess_pending_webhooks';

    protected $signature = 'shopify:reprocess-pending-webhooks
        {--limit=50 : Max events}
        {--min-age-seconds=120 : Only older than this}';

    protected $description = 'Redispatch stuck Shopify webhook events';

    public function handle(): int
    {
        if (! Schema::hasTable('shopify_webhook_events')) {
            $this->warn('shopify_webhook_events missing.');

            return self::SUCCESS;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $minAge = max(30, min(3600, (int) $this->option('min-age-seconds')));
        $cutoff = now()->subSeconds($minAge);

        $ids = ShopifyWebhookEvent::query()
            ->whereNull('processed_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            $this->info('No pending Shopify webhook events.');
            Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addDays(7));

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            ProcessShopifyWebhookJob::dispatch($id);
        }

        $this->info('Redispatched '.count($ids).' Shopify webhook event(s).');
        Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addDays(7));

        return self::SUCCESS;
    }
}
