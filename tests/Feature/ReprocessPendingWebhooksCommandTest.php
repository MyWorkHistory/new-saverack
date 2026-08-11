<?php

namespace Tests\Feature;

use App\Jobs\ProcessShipHeroOrderWebhookJob;
use App\Models\ShipHeroWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ReprocessPendingWebhooksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_redispatches_only_pending_order_webhooks_older_than_min_age(): void
    {
        Bus::fake();

        $oldPending = ShipHeroWebhookEvent::create([
            'event_id' => 'evt-old-pending',
            'event_type' => 'Shipment Update',
            'payload' => ['webhook_type' => 'Shipment Update', 'order_uuid' => 'T3JkZXI6MQ=='],
            'processed_at' => null,
        ]);
        $oldPending->created_at = now()->subMinutes(5);
        $oldPending->save();

        $freshPending = ShipHeroWebhookEvent::create([
            'event_id' => 'evt-fresh-pending',
            'event_type' => 'Shipment Update',
            'payload' => ['webhook_type' => 'Shipment Update', 'order_uuid' => 'T3JkZXI6Mg=='],
            'processed_at' => null,
        ]);
        $freshPending->created_at = now()->subSeconds(30);
        $freshPending->save();

        $alreadyProcessed = ShipHeroWebhookEvent::create([
            'event_id' => 'evt-done',
            'event_type' => 'Shipment Update',
            'payload' => ['webhook_type' => 'Shipment Update', 'order_uuid' => 'T3JkZXI6Mw=='],
            'processed_at' => now()->subMinute(),
        ]);
        $alreadyProcessed->created_at = now()->subMinutes(10);
        $alreadyProcessed->save();

        $inventoryPending = ShipHeroWebhookEvent::create([
            'event_id' => 'evt-inv',
            'event_type' => 'Inventory Update',
            'payload' => ['webhook_type' => 'Inventory Update', 'sku' => 'ABC'],
            'processed_at' => null,
        ]);
        $inventoryPending->created_at = now()->subMinutes(10);
        $inventoryPending->save();

        $exit = Artisan::call('orders:reprocess-pending-webhooks', [
            '--limit' => 50,
            '--min-age-seconds' => 120,
        ]);

        $this->assertSame(0, $exit);
        Bus::assertDispatched(ProcessShipHeroOrderWebhookJob::class, 1);
        Bus::assertDispatched(ProcessShipHeroOrderWebhookJob::class, function (ProcessShipHeroOrderWebhookJob $job) use ($oldPending) {
            return (int) $job->webhookEventId === (int) $oldPending->id;
        });
    }

    public function test_respects_batch_limit(): void
    {
        Bus::fake();

        for ($i = 0; $i < 3; $i++) {
            $event = ShipHeroWebhookEvent::create([
                'event_id' => 'evt-batch-'.$i,
                'event_type' => 'Order Allocated',
                'payload' => ['webhook_type' => 'Order Allocated', 'order_uuid' => 'ord-'.$i],
                'processed_at' => null,
            ]);
            $event->created_at = now()->subMinutes(10);
            $event->save();
        }

        Artisan::call('orders:reprocess-pending-webhooks', [
            '--limit' => 2,
            '--min-age-seconds' => 120,
        ]);

        Bus::assertDispatched(ProcessShipHeroOrderWebhookJob::class, 2);
    }
}
