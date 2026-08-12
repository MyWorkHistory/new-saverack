<?php

namespace App\Console\Commands;

use App\Jobs\ProcessShopifyWebhookJob;
use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyWebhookEvent;
use App\Services\ShopifyBootstrapImportService;
use App\Services\ShopifyClient;
use App\Services\ShopifyOrderSyncService;
use App\Services\ShopifyProductSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ShopifyDiagnoseCommand extends Command
{
    protected $signature = 'shopify:diagnose
        {--account= : Client account id}
        {--process-pending=20 : Also process N pending webhook events inline}';

    protected $description = 'Diagnose Shopify connection, queue, schedule, and pending webhooks';

    public function handle(
        ShopifyClient $client,
        ShopifyProductSyncService $products,
        ShopifyOrderSyncService $orders,
        ShopifyBootstrapImportService $bootstrap
    ): int {
        $query = ClientAccountShopifyConnection::query();
        $account = trim((string) $this->option('account'));
        if ($account !== '') {
            $query->where('client_account_id', (int) $account);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->error('No Shopify connections found.');

            return self::FAILURE;
        }

        $this->line('Queue default: '.(string) config('queue.default'));
        $this->line('SHOPIFY_WEBHOOK_URL: '.(string) config('services.shopify.webhook_url'));
        $this->line('schedule sync_recent last: '.(string) Cache::get(\App\Console\Commands\ShopifySyncRecentCommand::LAST_RUN_CACHE_KEY, 'never'));
        $this->line('schedule reprocess last: '.(string) Cache::get(\App\Console\Commands\ShopifyReprocessPendingWebhooksCommand::LAST_RUN_CACHE_KEY, 'never'));

        if (Schema::hasTable('jobs')) {
            $this->line('jobs table pending: '.(string) DB::table('jobs')->count());
        }
        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->where('payload', 'like', '%ProcessShopifyWebhookJob%')->count();
            $this->line('failed ProcessShopifyWebhookJob: '.(string) $failed);
        }

        foreach ($rows as $connection) {
            $this->line('');
            $this->info('Connection #'.$connection->id.' account '.$connection->client_account_id);
            $this->line('  status: '.$connection->status);
            $this->line('  shop_domain: '.$connection->normalizedShopDomain());
            $this->line('  aliases: '.implode(', ', $connection->allShopDomains()));
            $this->line('  has_token: '.($connection->hasCredentials() ? 'yes' : 'no'));
            $this->line('  last_sync_at: '.(string) $connection->last_sync_at);
            $this->line('  last_order_sync_at: '.(string) $connection->last_order_sync_at);
            $this->line('  last_error: '.(string) ($connection->last_error ?: '-'));

            try {
                $shop = $client->forConnection($connection)->shopInfo();
                $this->info('  API shopInfo OK: '.((string) ($shop['myshopifyDomain'] ?? '')).' / '.((string) ($shop['name'] ?? '')));
            } catch (Throwable $e) {
                $this->error('  API shopInfo FAILED: '.$e->getMessage());
            }

            $pending = ShopifyWebhookEvent::query()
                ->where(function ($q) use ($connection) {
                    $q->where('connection_id', $connection->id);
                    foreach ($connection->allShopDomains() as $domain) {
                        $q->orWhere('shop_domain', $domain);
                    }
                })
                ->whereNull('processed_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get(['id', 'event_id', 'topic', 'shop_domain', 'processing_error', 'created_at']);

            $this->line('  pending webhooks (latest 10): '.$pending->count());
            foreach ($pending as $event) {
                $this->line('    #'.$event->id.' '.$event->topic.' err='.((string) ($event->processing_error ?: '-')).' at '.$event->created_at);
            }

            $recent = ShopifyWebhookEvent::query()
                ->where('connection_id', $connection->id)
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'topic', 'processed_at', 'processing_error']);
            $this->line('  latest events:');
            foreach ($recent as $event) {
                $this->line(
                    '    #'.$event->id.' '.$event->topic
                    .' processed='.(string) ($event->processed_at ?: 'null')
                    .' err='.(string) ($event->processing_error ?: '-')
                );
            }
        }

        $limit = max(0, min(100, (int) $this->option('process-pending')));
        if ($limit > 0) {
            $ids = ShopifyWebhookEvent::query()
                ->whereNull('processed_at')
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');
            $this->line('');
            $this->info('Processing '.$ids->count().' pending webhook(s) inline…');
            foreach ($ids as $id) {
                try {
                    (new ProcessShopifyWebhookJob((int) $id))->handle($products, $orders, $bootstrap, $client);
                    $event = ShopifyWebhookEvent::query()->find($id);
                    $this->line(
                        '  #'.$id.' → processed='.(string) optional($event)->processed_at
                        .' err='.(string) (optional($event)->processing_error ?: '-')
                    );
                } catch (Throwable $e) {
                    $this->error('  #'.$id.' FAILED: '.$e->getMessage());
                }
            }
        }

        return self::SUCCESS;
    }
}
