<?php

namespace App\Console\Commands;

use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use Illuminate\Console\Command;

class ShopifyUnlockCatalogCommand extends Command
{
    protected $signature = 'shopify:unlock-catalog
        {--account= : Client account id}
        {--connection= : Connection id}';

    protected $description = 'Clear accidental CRM catalog locks so Shopify product/title updates apply again';

    public function handle(): int
    {
        $query = ClientAccountShopifyConnection::query();
        $account = trim((string) $this->option('account'));
        $connectionOpt = trim((string) $this->option('connection'));
        if ($account !== '') {
            $query->where('client_account_id', (int) $account);
        }
        if ($connectionOpt !== '') {
            $query->where('id', (int) $connectionOpt);
        }

        $ids = $query->pluck('id')->all();
        if ($ids === []) {
            $this->warn('No Shopify connections matched.');

            return self::SUCCESS;
        }

        $products = ShopifyProduct::query()
            ->whereIn('connection_id', $ids)
            ->whereNotNull('crm_locked_at')
            ->update(['crm_locked_at' => null]);
        $variants = ShopifyProductVariant::query()
            ->whereIn('connection_id', $ids)
            ->whereNotNull('crm_locked_at')
            ->update(['crm_locked_at' => null]);

        $this->info('Unlocked products: '.$products.'; variants: '.$variants);

        return self::SUCCESS;
    }
}
