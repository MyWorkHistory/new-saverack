<?php

namespace App\Console\Commands;

use App\Models\ClientAccountShopifyConnection;
use App\Services\ShopifyConnectionService;
use Illuminate\Console\Command;
use Throwable;

class ShopifyRegisterWebhooksCommand extends Command
{
    protected $signature = 'shopify:register-webhooks
        {--account= : Client account id}';

    protected $description = 'Register Shopify webhooks for connected store(s)';

    public function handle(ShopifyConnectionService $connections): int
    {
        $query = ClientAccountShopifyConnection::query()
            ->where('status', ClientAccountShopifyConnection::STATUS_CONNECTED);

        $account = trim((string) $this->option('account'));
        if ($account !== '') {
            $query->where('client_account_id', (int) $account);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->warn('No connected Shopify stores.');

            return self::SUCCESS;
        }

        foreach ($rows as $connection) {
            $this->line('Registering for #'.$connection->id.' '.$connection->normalizedShopDomain());
            try {
                $count = $connections->registerWebhooks($connection);
                $this->info('  Created/confirmed '.$count.' topic registration(s).');
            } catch (Throwable $e) {
                $this->error('  '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
