<?php

namespace App\Jobs;

use App\Models\ClientAccountShopifyConnection;
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
use RuntimeException;
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
            $connection = ClientAccountShopifyConnection::findByShopDomain((string) $event->shop_domain);
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
        $kind = $this->topicKind($topic);

        try {
            if ($kind === 'orders_delete') {
                $orderId = $orders->extractShopifyOrderId($payload);
                if ($orderId === '') {
                    throw new RuntimeException('orders/delete webhook missing order id.');
                }
                $orders->deleteOrderByShopifyId($connection, $orderId);
            } elseif ($kind === 'orders') {
                $orders->upsertOrderFromWebhookPayload($connection, $payload, $topic);
            } elseif ($kind === 'products_delete') {
                $productId = $products->extractShopifyProductId($payload);
                if ($productId === '') {
                    throw new RuntimeException('products/delete webhook missing product id.');
                }
                $products->deleteProductByShopifyId($connection, $productId);
            } elseif ($kind === 'products') {
                $productId = $products->extractShopifyProductId($payload);
                if ($productId === '') {
                    throw new RuntimeException('Product webhook missing product id.');
                }
                $force = str_contains($topic, 'create');
                $products->upsertProductFromShopifyNode($connection, $payload, $force);
            } elseif ($kind === 'inventory') {
                $this->markProcessed($event, 'Shopify inventory webhooks do not update CRM quantity.');

                return;
            } elseif ($kind === 'fulfillments') {
                $orderId = $orders->extractShopifyOrderId($payload);
                if ($orderId === '') {
                    $orderId = ShopifyGid::toId((string) ($payload['order_id'] ?? ''));
                }
                if ($orderId === '') {
                    throw new RuntimeException('Fulfillment webhook missing order id.');
                }
                if ($orders->upsertOrderFromRestId($connection, $orderId) === null
                    && $orders->refreshOrderByShopifyId($connection, $orderId, 1) === null) {
                    throw new RuntimeException('Fulfillment webhook could not refresh order '.$orderId);
                }
            } else {
                $this->markProcessed($event, 'Unhandled Shopify topic: '.$topic);

                return;
            }

            $event->processed_at = now();
            $event->processing_error = null;
            $event->save();
            Log::info('shopify.webhook.processed', [
                'event_id' => $event->event_id,
                'topic' => $event->topic,
                'connection_id' => $connection->id,
            ]);
        } catch (Throwable $e) {
            $event->processing_error = mb_substr($e->getMessage(), 0, 500);
            $event->save();
            Log::warning('shopify.webhook.failed', [
                'event_id' => $event->event_id,
                'topic' => $event->topic,
                'connection_id' => $connection->id ?? null,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function topicKind(string $topic): string
    {
        $normalized = strtolower(str_replace('_', '/', trim($topic)));

        if ($normalized === 'orders/delete' || $normalized === 'orders/deleted') {
            return 'orders_delete';
        }
        if (str_starts_with($normalized, 'orders/')) {
            return 'orders';
        }
        if ($normalized === 'products/delete' || $normalized === 'products/deleted') {
            return 'products_delete';
        }
        if (str_starts_with($normalized, 'products/')) {
            return 'products';
        }
        if (str_contains($normalized, 'inventory')) {
            return 'inventory';
        }
        if (str_starts_with($normalized, 'fulfillments/')) {
            return 'fulfillments';
        }

        return 'unknown';
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
