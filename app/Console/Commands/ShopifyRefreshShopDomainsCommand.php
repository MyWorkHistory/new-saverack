<?php

namespace App\Console\Commands;

use App\Models\ClientAccountShopifyConnection;
use App\Services\ShopifyClient;
use Illuminate\Console\Command;
use Throwable;

class ShopifyRefreshShopDomainsCommand extends Command
{
    protected $signature = 'shopify:refresh-shop-domains
        {--account= : Client account id}
        {--add= : Extra alias domain to merge (e.g. 1gwr02-06.myshopify.com)}';

    protected $description = 'Refresh Shopify connection domain aliases (handles renamed *.myshopify.com hosts)';

    public function handle(ShopifyClient $client): int
    {
        $query = ClientAccountShopifyConnection::query()
            ->whereNotNull('admin_api_access_token');

        $account = trim((string) $this->option('account'));
        if ($account !== '') {
            $query->where('client_account_id', (int) $account);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->warn('No Shopify connections found.');

            return self::SUCCESS;
        }

        $extra = trim((string) $this->option('add'));

        foreach ($rows as $connection) {
            $this->line('#'.$connection->id.' account '.$connection->client_account_id.' ('.$connection->normalizedShopDomain().')');
            try {
                $shop = $client->forConnection($connection)->shopInfo();
                $canonical = ClientAccountShopifyConnection::normalizeShopDomain((string) ($shop['myshopifyDomain'] ?? ''));
                if ($canonical !== '') {
                    $connection->shop_domain = $canonical;
                }
                if (! empty($shop['name'])) {
                    $connection->shop_name = (string) $shop['name'];
                }
                $seeds = is_array($shop['domains'] ?? null) ? $shop['domains'] : [];
                if ($extra !== '') {
                    $seeds[] = $extra;
                }
                $connection->mergeShopDomainAliases($seeds);
                $connection->save();

                $this->info('  Domains: '.implode(', ', $connection->allShopDomains()));
            } catch (Throwable $e) {
                if ($extra !== '') {
                    $connection->mergeShopDomainAliases([$extra]);
                    $connection->save();
                    $this->warn('  API refresh failed ('.$e->getMessage().'); merged --add only.');
                    $this->info('  Domains: '.implode(', ', $connection->allShopDomains()));
                } else {
                    $this->error('  '.$e->getMessage());
                }
            }
        }

        return self::SUCCESS;
    }
}
