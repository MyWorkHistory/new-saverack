<?php

namespace App\Jobs;

use App\Models\ShipHeroWebhookEvent;
use App\Services\OrderDashboardSnapshotService;
use App\Services\PortalQueueCountsService;
use App\Services\ShipHeroOrderDetailCacheService;
use App\Services\ShipHeroOrderQueueIndexService;
use App\Services\ShipHeroWebhookPayloadResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessShipHeroOrderWebhookJob implements ShouldQueue
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
        ShipHeroOrderQueueIndexService $index,
        OrderDashboardSnapshotService $snapshots,
        PortalQueueCountsService $queueCounts,
        ShipHeroOrderDetailCacheService $detailCache
    ): void {
        $event = ShipHeroWebhookEvent::query()->find($this->webhookEventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $orderId = $resolver->extractOrderId($payload);
        if ($orderId === '') {
            $this->markProcessed($event, 'No order id in webhook payload.');

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
            $event->shiphero_order_id = $orderId;
            $event->save();
        }

        try {
            $affectedTabs = $index->reconcileOrder($clientAccountId, $orderId);
            $detailCache->clearOrder($clientAccountId, $orderId);

            foreach ($affectedTabs as $tab) {
                $snapshots->patchAccountFromQueueTab($clientAccountId, $tab);
            }

            if ($affectedTabs !== []) {
                $queueCounts->refreshQueueCacheFromIndex($clientAccountId, $affectedTabs);
                $queueCounts->bumpCountsRevision($clientAccountId);
                $snapshots->bumpDashboardRevision();
            }

            $event->processed_at = now();
            $event->processing_error = null;
            $event->save();

            Log::info('shiphero.webhook.processed', [
                'event_id' => $event->event_id,
                'event_type' => $event->event_type,
                'client_account_id' => $clientAccountId,
                'order_id' => $orderId,
                'affected_tabs' => $affectedTabs,
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

        Log::warning('shiphero.webhook.skipped', [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'message' => $message,
        ]);
    }
}
