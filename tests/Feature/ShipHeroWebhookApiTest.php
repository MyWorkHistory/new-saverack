<?php

namespace Tests\Feature;

use App\Jobs\ProcessShipHeroInventoryWebhookJob;
use App\Jobs\ProcessShipHeroOrderWebhookJob;
use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\ShipHeroOrderQueueIndex;
use App\Models\ShipHeroWebhookEvent;
use App\Models\User;
use App\Models\WholesaleOrder;
use App\Services\OrderDashboardSnapshotService;
use App\Services\PortalQueueCountsService;
use App\Services\ShipHeroOrderDetailCacheService;
use App\Services\ShipHeroOrderQueueIndexService;
use App\Services\ShipHeroOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShipHeroWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shiphero.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function signPayload(string $payload): string
    {
        return base64_encode(hash_hmac('sha256', $payload, self::WEBHOOK_SECRET, true));
    }

    public function test_head_request_is_allowed_for_shiphero_validation(): void
    {
        $this->call('HEAD', '/api/shiphero/webhook')->assertOk();
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = json_encode(['webhook_type' => 'Shipment Update', 'order_uuid' => 'T3JkZXI6MQ==']);

        $this->postJson('/api/shiphero/webhook', json_decode($payload, true), [
            'X-Shiphero-Hmac-Sha256' => 'invalid',
        ])->assertUnauthorized();
    }

    public function test_webhook_accepts_valid_payload_and_dispatches_job(): void
    {
        Bus::fake();

        $account = ClientAccount::create([
            'company_name' => 'Webhook Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-webhook-1',
        ]);

        $payload = json_encode([
            'webhook_type' => 'Shipment Update',
            'order_uuid' => 'T3JkZXI6MTIz',
            'account_id' => 'sh-webhook-1',
        ]);

        $this->call(
            'POST',
            '/api/shiphero/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SHIPHERO_HMAC_SHA256' => $this->signPayload($payload),
                'HTTP_X_SHIPHERO_MESSAGE_ID' => 'msg-test-1',
            ],
            $payload
        )->assertOk()->assertJsonPath('Status', 'Success');

        $this->assertDatabaseHas('shiphero_webhook_events', [
            'event_id' => 'msg-test-1',
            'event_type' => 'Shipment Update',
            'client_account_id' => $account->id,
        ]);

        Bus::assertDispatched(ProcessShipHeroOrderWebhookJob::class);
    }

    public function test_inventory_webhook_accepts_valid_payload_and_dispatches_inventory_job(): void
    {
        Bus::fake();

        $account = ClientAccount::create([
            'company_name' => 'Inventory Webhook Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-inv-webhook-1',
        ]);

        $payload = json_encode([
            'webhook_type' => 'Inventory Update',
            'account_id' => 'sh-inv-webhook-1',
            'inventory' => [
                ['sku' => 'TEST-SKU-1'],
            ],
        ]);

        $this->call(
            'POST',
            '/api/shiphero/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SHIPHERO_HMAC_SHA256' => $this->signPayload($payload),
                'HTTP_X_SHIPHERO_MESSAGE_ID' => 'msg-inv-1',
            ],
            $payload
        )->assertOk()->assertJsonPath('Status', 'Success');

        $this->assertDatabaseHas('shiphero_webhook_events', [
            'event_id' => 'msg-inv-1',
            'event_type' => 'Inventory Update',
            'client_account_id' => $account->id,
        ]);

        Bus::assertDispatched(ProcessShipHeroInventoryWebhookJob::class);
        Bus::assertNotDispatched(ProcessShipHeroOrderWebhookJob::class);
    }

    public function test_duplicate_webhook_message_is_idempotent(): void
    {
        Bus::fake();

        ClientAccount::create([
            'company_name' => 'Webhook Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-webhook-2',
        ]);

        $payload = json_encode([
            'webhook_type' => 'Order Canceled',
            'order_uuid' => 'T3JkZXI6NDU2',
            'account_id' => 'sh-webhook-2',
        ]);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHIPHERO_HMAC_SHA256' => $this->signPayload($payload),
            'HTTP_X_SHIPHERO_MESSAGE_ID' => 'msg-dup-1',
        ];

        $this->call('POST', '/api/shiphero/webhook', [], [], [], $headers, $payload)->assertOk();
        $this->call('POST', '/api/shiphero/webhook', [], [], [], $headers, $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, ShipHeroWebhookEvent::query()->where('event_id', 'msg-dup-1')->count());
        Bus::assertDispatched(ProcessShipHeroOrderWebhookJob::class, 1);
    }

    public function test_webhook_job_reconciles_order_and_bumps_revision(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Webhook Job Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-job-1',
        ]);

        ShipHeroOrderQueueIndex::create([
            'client_account_id' => $account->id,
            'shiphero_order_id' => 'T3JkZXI6MTAw',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_AWAITING,
            'ready_to_ship' => true,
            'has_backorder' => false,
            'list_payload' => ['id' => 'T3JkZXI6MTAw', 'display_status' => 'Ready To Ship'],
            'indexed_at' => now(),
            'last_seen_at' => now(),
        ]);

        $orders = Mockery::mock(ShipHeroOrderService::class);
        $orders->shouldReceive('fetchOrderListRowForIndex')
            ->once()
            ->andReturn([
                'id' => 'T3JkZXI6MTAw',
                'status' => 'fulfilled',
                'raw_fulfillment_status' => 'fulfilled',
                'raw_status' => '',
                'has_backorder' => false,
                'has_active_hold' => false,
                'display_status' => 'Fulfilled',
                'order_number' => '#100',
                'recipient_name' => 'Test User',
                'order_date' => now()->toIso8601String(),
                'ship_date' => now()->toIso8601String(),
                'country' => 'US',
                'method' => 'Ground',
                'holds' => [],
            ]);
        $orders->shouldReceive('classifyOrderQueueTab')
            ->once()
            ->andReturn(ShipHeroOrderQueueIndex::KIND_SHIPPED);
        $this->app->instance(ShipHeroOrderService::class, $orders);

        $event = ShipHeroWebhookEvent::create([
            'event_id' => 'msg-job-1',
            'event_type' => 'Shipment Update',
            'client_account_id' => $account->id,
            'shiphero_order_id' => 'T3JkZXI6MTAw',
            'payload' => ['order_uuid' => 'T3JkZXI6MTAw'],
        ]);

        $job = new ProcessShipHeroOrderWebhookJob((int) $event->id);
        $job->handle(
            app(\App\Services\ShipHeroWebhookPayloadResolver::class),
            app(ShipHeroOrderQueueIndexService::class),
            app(OrderDashboardSnapshotService::class),
            app(PortalQueueCountsService::class),
            app(ShipHeroOrderDetailCacheService::class)
        );

        $this->assertDatabaseMissing('shiphero_order_queue_index', [
            'client_account_id' => $account->id,
            'shiphero_order_id' => 'T3JkZXI6MTAw',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_AWAITING,
        ]);
        $this->assertDatabaseHas('shiphero_order_queue_index', [
            'client_account_id' => $account->id,
            'shiphero_order_id' => 'T3JkZXI6MTAw',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_SHIPPED,
        ]);

        $revision = Cache::get('orders:queue_counts:revision:'.$account->id);
        $this->assertGreaterThan(0, (int) $revision);
    }

    public function test_shipment_update_marks_wholesale_order_shipped_for_numeric_id(): void
    {
        $account = $this->accountForWholesaleWebhook('wh-ship-1');
        $order = $this->wholesaleOrderForWebhook($account, '100', WholesaleOrder::STATUS_IN_PROGRESS);

        $this->runShipmentUpdateWebhookJob($account, 'T3JkZXI6MTAw');

        $this->assertDatabaseHas('wholesale_orders', [
            'id' => $order->id,
            'status' => WholesaleOrder::STATUS_SHIPPED,
        ]);
    }

    public function test_shipment_update_matches_graphql_order_id_to_numeric_shiphero_order_id(): void
    {
        $account = $this->accountForWholesaleWebhook('wh-ship-2');
        $storedGraphql = 'T3JkZXI6ODc0NTE4MjUz';
        $order = $this->wholesaleOrderForWebhook($account, $storedGraphql, WholesaleOrder::STATUS_COMPLETED);

        $this->runShipmentUpdateWebhookJob($account, '874518253');

        $this->assertDatabaseHas('wholesale_orders', [
            'id' => $order->id,
            'shiphero_order_id' => $storedGraphql,
            'status' => WholesaleOrder::STATUS_SHIPPED,
        ]);
    }

    private function accountForWholesaleWebhook(string $suffix): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'Wholesale Webhook '.$suffix,
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-'.$suffix,
        ]);
    }

    private function wholesaleOrderForWebhook(ClientAccount $account, string $shipheroOrderId, string $status): WholesaleOrder
    {
        return WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'WO-'.$shipheroOrderId,
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => $status,
            'items_count' => 1,
            'shiphero_order_id' => $shipheroOrderId,
        ]);
    }

    private function runShipmentUpdateWebhookJob(ClientAccount $account, string $webhookOrderId): void
    {
        $orders = Mockery::mock(ShipHeroOrderService::class);
        $orders->shouldReceive('fetchOrderListRowForIndex')->andReturn(null);
        $this->app->instance(ShipHeroOrderService::class, $orders);

        $event = ShipHeroWebhookEvent::create([
            'event_id' => 'msg-wholesale-'.$webhookOrderId,
            'event_type' => 'Shipment Update',
            'client_account_id' => $account->id,
            'shiphero_order_id' => $webhookOrderId,
            'payload' => ['order_uuid' => $webhookOrderId],
        ]);

        $job = new ProcessShipHeroOrderWebhookJob((int) $event->id);
        $job->handle(
            app(\App\Services\ShipHeroWebhookPayloadResolver::class),
            app(ShipHeroOrderQueueIndexService::class),
            app(OrderDashboardSnapshotService::class),
            app(PortalQueueCountsService::class),
            app(ShipHeroOrderDetailCacheService::class)
        );
    }
}
