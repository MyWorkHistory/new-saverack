<?php

namespace App\Jobs;

use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyWebhookEvent;
use App\Services\ShopifyBootstrapImportService;
use App\Services\ShopifyClient;
use App\Services\ShopifyOrderSyncService;
use App\Services\ShopifyProductSyncService;
use App\Support\ShopifyGid;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessShopifyWebhookJob implements ShouldQueue
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
        ShopifyProductSyncService $products,
        ShopifyOrderSyncService $orders,
        ShopifyBootstrapImportService $bootstrap,
        ShopifyClient $client
    ): void {
        $event = ShopifyWebhookEvent::query()->find($this->webhookEventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $connection = $event->connection_id
            ? ClientAccountShopifyConnection::query()->find($event->connection_id)
            : null;
        if ($connection === null && $event->shop_domain) {
            $domain = strtolower(trim((string) $event->shop_domain));
            $connection = ClientAccountShopifyConnection::query()
                ->where('shop_domain', $domain)
                ->orWhere('shop_domain', 'https://'.$domain)
                ->first();
            if ($connection !== null) {
                $event->connection_id = $connection->id;
                $event->save();
            }
        }

        if ($connection === null || ! $connection->hasCredentials()) {
            $this->markProcessed($event, 'No Shopify connection for webhook shop.');

            return;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $topic = strtolower(trim((string) $event->topic));

        try {
            if (str_starts_with($topic, 'orders/') || in_array($topic, ['orders_create', 'orders_updated', 'orders_cancelled'], true)) {
                $orders->upsertOrderFromWebhookPayload($connection, $payload);
            } elseif (str_starts_with($topic, 'products/') || in_array($topic, ['products_create', 'products_update'], true)) {
                $force = str_contains($topic, 'create');
                $products->upsertProductFromShopifyNode($connection, $payload, $force);
            } elseif (str_contains($topic, 'inventory_levels') || str_contains($topic, 'inventory_levels_update')) {
                $this->applyInventoryLevel($connection, $payload, $bootstrap, $client);
            } elseif (str_starts_with($topic, 'fulfillments/')) {
                $orderId = ShopifyGid::toId((string) ($payload['order_id'] ?? ''));
                if ($orderId !== '') {
                    $orders->refreshOrderByShopifyId($connection, $orderId);
                }
            }

            $event->processed_at = now();
            $event->processing_error = null;
            $event->save();
        } catch (Throwable $e) {
            $event->processing_error = mb_substr($e->getMessage(), 0, 500);
            $event->save();
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyInventoryLevel(
        ClientAccountShopifyConnection $connection,
        array $payload,
        ShopifyBootstrapImportService $bootstrap,
        ShopifyClient $client
    ): void {
        $inventoryItemId = ShopifyGid::toId((string) ($payload['inventory_item_id'] ?? ''));
        $locationId = ShopifyGid::toId((string) ($payload['location_id'] ?? ''));
        if ($inventoryItemId === '') {
            return;
        }

        if ($locationId !== '' && array_key_exists('available', $payload)) {
            ShopifyInventoryLevel::query()->updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'shopify_inventory_item_id' => $inventoryItemId,
                    'shopify_location_id' => $locationId,
                ],
                [
                    'available' => (int) $payload['available'],
                    'shopify_updated_at' => now(),
                ]
            );

            return;
        }

        $bootstrap->syncInventoryItemLevels(
            $connection,
            $client->forConnection($connection),
            $inventoryItemId
        );
    }

    private function markProcessed(ShopifyWebhookEvent $event, string $message): void
    {
        $event->processed_at = now();
        $event->processing_error = mb_substr($message, 0, 500);
        $event->save();
        Log::warning('shopify.webhook.skipped', [
            'event_id' => $event->event_id,
            'topic' => $event->topic,
            'message' => $message,
        ]);
    }
}
