<?php

namespace App\Console\Commands;

use App\Models\ClientAccountShopifyConnection;
use App\Services\ShopifyConnectionService;
use App\Services\ShopifyOrderSyncService;
use App\Services\ShopifyProductSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ShopifySyncRecentCommand extends Command
{
    public const LAST_RUN_CACHE_KEY = 'shopify:schedule:last_run:sync_recent';

    protected $signature = 'shopify:sync-recent
        {--minutes=15 : Lookback window}
        {--connection= : Optional connection id}';

    protected $description = 'Reconcile recent Shopify products/orders (webhook backup)';

    public function handle(ShopifyOrderSyncService $orders, ShopifyProductSyncService $products): int
    {
        $minutes = max(5, min(120, (int) $this->option('minutes')));
        $query = ClientAccountShopifyConnection::query()
            ->whereNotNull('admin_api_access_token')
            ->whereIn('status', [
                ClientAccountShopifyConnection::STATUS_CONNECTED,
                ClientAccountShopifyConnection::STATUS_ERROR,
            ]);

        $connectionOpt = trim((string) $this->option('connection'));
        if ($connectionOpt !== '') {
            $query->where('id', (int) $connectionOpt);
        }

        $connections = $query->get();
        if ($connections->isEmpty()) {
            $this->warn('No Shopify stores with credentials (status connected/error).');

            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            $this->line('Syncing connection #'.$connection->id.' ('.$connection->normalizedShopDomain().')…');
            try {
                $orderCount = $orders->syncRecentlyUpdated($connection, $minutes);
                $catalog = $products->importActiveProducts($connection);
                $this->info('  Orders refreshed: '.$orderCount.'; products scanned: '.($catalog['products'] ?? 0));
                $connection->last_sync_at = now();
                $connection->last_product_sync_at = now();
                if ($connection->status === ClientAccountShopifyConnection::STATUS_ERROR) {
                    $connection->status = ClientAccountShopifyConnection::STATUS_CONNECTED;
                }
                $connection->last_error = null;
                $connection->save();
            } catch (Throwable $e) {
                $this->error('  '.$e->getMessage());
                $connection->last_error = mb_substr($e->getMessage(), 0, 1000);
                $connection->status = ClientAccountShopifyConnection::STATUS_ERROR;
                $connection->save();
            }
        }

        Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addDays(7));

        return self::SUCCESS;
    }
}
