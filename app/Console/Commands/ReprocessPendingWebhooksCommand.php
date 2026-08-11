<?php

namespace App\Console\Commands;

use App\Jobs\ProcessShipHeroOrderWebhookJob;
use App\Models\ShipHeroWebhookEvent;
use App\Services\ShipHeroWebhookRegistrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ReprocessPendingWebhooksCommand extends Command
{
    public const LAST_RUN_CACHE_KEY = 'shiphero:schedule:last_run:orders_reprocess_pending_webhooks';

    protected $signature = 'orders:reprocess-pending-webhooks
        {--limit=50 : Max events to redispatch}
        {--min-age-seconds=120 : Only events older than this (avoid racing in-flight jobs)}';

    protected $description = 'Redispatch stuck order webhook events that never finished processing (webhook fallback)';

    public function handle(): int
    {
        if (! Schema::hasTable('shiphero_webhook_events')) {
            $this->warn('shiphero_webhook_events table not found.');

            return self::SUCCESS;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $minAgeSeconds = max(30, min(3600, (int) $this->option('min-age-seconds')));
        $cutoff = now()->subSeconds($minAgeSeconds);

        $ids = ShipHeroWebhookEvent::query()
            ->whereNull('processed_at')
            ->whereIn('event_type', ShipHeroWebhookRegistrationService::ORDER_WEBHOOK_NAMES)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            $this->info('No pending order webhook events to redispatch.');
            Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addDays(7));

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            ProcessShipHeroOrderWebhookJob::dispatch($id);
        }

        $this->info('Redispatched '.count($ids).' pending order webhook event(s).');
        Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addDays(7));

        return self::SUCCESS;
    }
}
