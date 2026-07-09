<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessShipHeroInventoryWebhookJob;
use App\Jobs\ProcessShipHeroOrderWebhookJob;
use App\Models\ShipHeroWebhookEvent;
use App\Services\ShipHeroWebhookPayloadResolver;
use App\Services\ShipHeroWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ShipHeroWebhookController extends Controller
{
    public function handle(
        Request $request,
        ShipHeroWebhookVerifier $verifier,
        ShipHeroWebhookPayloadResolver $resolver
    ): JsonResponse|Response {
        if ($request->isMethod('HEAD')) {
            return response('', 200);
        }

        $secret = trim((string) config('services.shiphero.webhook_secret', ''));
        if ($secret === '') {
            return response()->json([
                'code' => '500',
                'Status' => 'Webhook secret is not configured.',
            ], 500);
        }

        $payload = (string) $request->getContent();
        $signature = (string) $request->header('X-Shiphero-Hmac-Sha256', $request->header('HTTP_X_SHIPHERO_HMAC_SHA256', ''));

        if (! $verifier->verify($payload, $signature, $secret)) {
            Log::warning('shiphero.webhook.invalid_signature');

            return response()->json([
                'code' => '401',
                'Status' => 'Invalid signature.',
            ], 401);
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return response()->json([
                'code' => '400',
                'Status' => 'Invalid JSON payload.',
            ], 400);
        }

        $messageId = trim((string) $request->header('X-Shiphero-Message-ID', ''));
        $eventType = $resolver->eventType($decoded);
        $orderId = $resolver->extractOrderId($decoded);
        $eventId = $messageId !== ''
            ? $messageId
            : hash('sha256', $eventType.'|'.$orderId.'|'.$payload);

        if (ShipHeroWebhookEvent::query()->where('event_id', $eventId)->exists()) {
            return response()->json([
                'code' => '200',
                'Status' => 'Success',
                'duplicate' => true,
            ]);
        }

        $account = $resolver->resolveClientAccount($decoded);

        $event = ShipHeroWebhookEvent::query()->create([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'client_account_id' => $account !== null ? (int) $account->id : null,
            'shiphero_order_id' => $orderId !== '' ? $orderId : null,
            'payload' => $decoded,
        ]);

        if ($resolver->isInventoryWebhookType($eventType)) {
            ProcessShipHeroInventoryWebhookJob::dispatch((int) $event->id)->afterResponse();
        } elseif ($resolver->isOrderWebhookType($eventType)) {
            ProcessShipHeroOrderWebhookJob::dispatch((int) $event->id)->afterResponse();
        } else {
            Log::warning('shiphero.webhook.unhandled_type', [
                'event_type' => $eventType,
                'event_id' => $eventId,
            ]);
        }

        return response()->json([
            'code' => '200',
            'Status' => 'Success',
        ]);
    }
}
