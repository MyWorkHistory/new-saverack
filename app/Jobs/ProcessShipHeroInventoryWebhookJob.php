<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Models\ShipHeroWebhookEvent;
use App\Services\ShipHeroInventoryService;
use App\Services\ShipHeroWebhookPayloadResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessShipHeroInventoryWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $webhookEventId;

    public $timeout = 300;

    public $tries = 3;

    public function __construct(int $webhookEventId)
    {
        $this->webhookEventId = $webhookEventId;
        $this->onConnection((string) config('queue.default', 'database'));
    }

    public function handle(
        ShipHeroWebhookPayloadResolver $resolver,
        ShipHeroInventoryService $inventory
    ): void {
        $event = ShipHeroWebhookEvent::query()->find($this->webhookEventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $skus = $resolver->extractSkus($payload);
        if ($skus === []) {
            $this->markProcessed($event, 'No SKU in webhook payload.');

            return;
        }

        $clientAccountId = (int) ($event->client_account_id ?? 0);
        if ($clientAccountId <= 0) {
            $account = $resolver->resolveClientAccount($payload);
            if ($account === null) {
                $this->markProcessed($event, 'Could not resolve client account.');

                return;
            }
            $clientAccountId = (int) $account->id;
            $event->client_account_id = $clientAccountId;
            $event->save();
        }

        $account = ClientAccount::query()->find($clientAccountId);
        if ($account === null) {
            $this->markProcessed($event, 'Client account not found.');

            return;
        }

        $customerId = trim((string) ($account->shiphero_customer_account_id ?? ''));
        if ($customerId === '') {
            $this->markProcessed($event, 'Client account is not linked to ShipHero.');

            return;
        }

        try {
            $synced = 0;
            foreach ($skus as $sku) {
                try {
                    $inventory->syncCatalogProductBySku($clientAccountId, $customerId, $sku);
                    $synced++;
                } catch (Throwable $e) {
                    Log::warning('shiphero.inventory_webhook.sku_failed', [
                        'client_account_id' => $clientAccountId,
                        'sku' => $sku,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            if ($synced > 0) {
                $inventory->bumpCatalogRevision($clientAccountId);
            }

            $event->processed_at = now();
            $event->processing_error = $synced > 0 ? null : 'No SKUs synced.';
            $event->save();

            Log::info('shiphero.inventory_webhook.processed', [
                'event_id' => $event->event_id,
                'event_type' => $event->event_type,
                'client_account_id' => $clientAccountId,
                'skus' => $skus,
                'synced' => $synced,
            ]);
        } catch (Throwable $e) {
            $event->processing_error = mb_substr($e->getMessage(), 0, 500);
            $event->save();
            throw $e;
        }
    }

    private function markProcessed(ShipHeroWebhookEvent $event, string $message): void
    {
        $event->processed_at = now();
        $event->processing_error = mb_substr($message, 0, 500);
        $event->save();

        Log::warning('shiphero.inventory_webhook.skipped', [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'message' => $message,
        ]);
    }
}
