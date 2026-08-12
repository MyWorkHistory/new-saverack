<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessShopifyWebhookJob;
use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyWebhookEvent;
use App\Services\ShopifyWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    /**
     * @return JsonResponse|Response
     */
    public function handle(Request $request, ShopifyWebhookVerifier $verifier)
    {
        if ($request->isMethod('HEAD')) {
            return response('', 200);
        }

        $raw = (string) $request->getContent();
        $hmac = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));
        $topic = strtolower(trim((string) $request->header('X-Shopify-Topic', '')));
        $webhookId = trim((string) $request->header('X-Shopify-Webhook-Id', ''));

        $connection = $shopDomain !== ''
            ? ClientAccountShopifyConnection::findByShopDomain($shopDomain)
            : null;

        $secrets = [];
        if ($connection !== null && is_string($connection->webhook_secret) && trim($connection->webhook_secret) !== '') {
            $secrets[] = trim($connection->webhook_secret);
        }
        $fallback = trim((string) config('services.shopify.webhook_secret', ''));
        if ($fallback !== '' && ! in_array($fallback, $secrets, true)) {
            $secrets[] = $fallback;
        }

        if ($secrets === []) {
            Log::warning('shopify.webhook.missing_secret', ['shop' => $shopDomain, 'topic' => $topic]);

            return response()->json(['message' => 'Webhook secret is not configured.'], 500);
        }

        $hmacOk = false;
        foreach ($secrets as $secret) {
            if ($verifier->verify($raw, $hmac, $secret)) {
                $hmacOk = true;
                break;
            }
        }

        if (! $hmacOk) {
            Log::warning('shopify.webhook.invalid_hmac', ['shop' => $shopDomain, 'topic' => $topic]);

            return response()->json(['message' => 'Invalid HMAC.'], 401);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return response()->json(['message' => 'Invalid JSON.'], 400);
        }

        $eventId = $webhookId !== ''
            ? $webhookId
            : hash('sha256', $topic.'|'.$shopDomain.'|'.$raw);

        if (ShopifyWebhookEvent::query()->where('event_id', $eventId)->exists()) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $event = ShopifyWebhookEvent::query()->create([
            'event_id' => $eventId,
            'topic' => $topic !== '' ? $topic : 'unknown',
            'shop_domain' => $shopDomain !== '' ? $shopDomain : null,
            'connection_id' => $connection !== null ? (int) $connection->id : null,
            'payload' => $decoded,
        ]);

        Log::info('shopify.webhook.received', [
            'event_id' => $event->event_id,
            'topic' => $event->topic,
            'shop' => $shopDomain,
            'connection_id' => $event->connection_id,
        ]);

        // Queue immediately (do not rely on afterResponse — PHP-FPM can drop terminating callbacks).
        ProcessShopifyWebhookJob::dispatch((int) $event->id);

        return response()->json(['ok' => true]);
    }
}
