<?php

namespace App\Console\Commands;

use App\Services\ShipHeroWebhookRegistrationService;
use Illuminate\Console\Command;

class RegisterShipHeroWebhooksCommand extends Command
{
    protected $signature = 'shiphero:register-webhooks
        {--url= : Public webhook URL (defaults to SHIPHERO_WEBHOOK_URL)}
        {--shop=saverack : ShipHero shop_name identifier}';

    protected $description = 'Register ShipHero order webhooks for near-real-time dashboard updates';

    public function handle(ShipHeroWebhookRegistrationService $registration): int
    {
        $url = trim((string) ($this->option('url') ?: config('services.shiphero.webhook_url', '')));
        if ($url === '') {
            $this->error('Set SHIPHERO_WEBHOOK_URL or pass --url=');

            return self::FAILURE;
        }

        $shop = trim((string) $this->option('shop'));
        $result = $registration->registerOrderWebhooks($url, $shop);

        foreach ($result['skipped'] as $name) {
            $this->line('Skipped (already registered): '.$name);
        }
        foreach ($result['created'] as $name) {
            $this->info('Registered: '.$name);
        }

        if ($result['secrets'] !== []) {
            $this->warn('Save SHIPHERO_WEBHOOK_SECRET from registration (shown once by ShipHero):');
            foreach ($result['secrets'] as $entry) {
                $this->line($entry['name'].': '.$entry['secret']);
            }
        }

        return self::SUCCESS;
    }
}
