<?php

namespace Tests\Unit;

use App\Jobs\ProcessShopifyWebhookJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderLineItem;
use App\Models\ShopifyWebhookEvent;
use App\Services\ShopifyOrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyOrderSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_order_id_from_orders_edited_payload(): void
    {
        $service = app(ShopifyOrderSyncService::class);

        $this->assertSame('820982911946154508', $service->extractShopifyOrderId([
            'order_edit' => [
                'id' => 789123,
                'order_id' => 820982911946154508,
            ],
        ]));

        $this->assertSame('55', $service->extractShopifyOrderId([
            'id' => 55,
            'admin_graphql_api_id' => 'gid://shopify/Order/55',
            'name' => '#1001',
        ]));
    }

    public function test_uses_current_quantity_and_prunes_removed_line_items(): void
    {
        $connection = $this->connection();
        $service = app(ShopifyOrderSyncService::class);

        $service->upsertOrderFromShopifyNode($connection, $this->orderNode([
            ['id' => 'gid://shopify/LineItem/1', 'quantity' => 2, 'currentQuantity' => 2, 'unfulfilledQuantity' => 2],
            ['id' => 'gid://shopify/LineItem/2', 'quantity' => 1, 'currentQuantity' => 1, 'unfulfilledQuantity' => 1],
        ]));

        $order = ShopifyOrder::query()->where('shopify_order_id', '99')->first();
        $this->assertNotNull($order);
        $this->assertSame(2, ShopifyOrderLineItem::query()->where('shopify_order_id', $order->id)->count());

        $service->upsertOrderFromShopifyNode($connection, $this->orderNode([
            ['id' => 'gid://shopify/LineItem/1', 'quantity' => 2, 'currentQuantity' => 5, 'unfulfilledQuantity' => 5],
        ]));

        $lines = ShopifyOrderLineItem::query()->where('shopify_order_id', $order->id)->get();
        $this->assertCount(1, $lines);
        $this->assertSame('1', $lines[0]->shopify_line_item_id);
        $this->assertSame(5, (int) $lines[0]->quantity);
        $this->assertSame(5, (int) $lines[0]->fulfillable_quantity);
    }

    public function test_delete_webhook_removes_crm_order(): void
    {
        $connection = $this->connection();
        $order = ShopifyOrder::query()->create([
            'connection_id' => $connection->id,
            'shopify_order_id' => '77',
            'name' => '#1007',
        ]);
        ShopifyOrderLineItem::query()->create([
            'connection_id' => $connection->id,
            'shopify_order_id' => $order->id,
            'shopify_line_item_id' => '701',
            'quantity' => 1,
        ]);

        $event = ShopifyWebhookEvent::query()->create([
            'event_id' => 'wh-delete-77',
            'topic' => 'orders/delete',
            'shop_domain' => 'test.myshopify.com',
            'connection_id' => $connection->id,
            'payload' => ['id' => 77, 'admin_graphql_api_id' => 'gid://shopify/Order/77'],
        ]);

        (new ProcessShopifyWebhookJob((int) $event->id))->handle(
            app(\App\Services\ShopifyProductSyncService::class),
            app(ShopifyOrderSyncService::class),
            app(\App\Services\ShopifyBootstrapImportService::class),
            app(\App\Services\ShopifyClient::class)
        );

        $this->assertDatabaseMissing('shopify_orders', ['id' => $order->id]);
        $this->assertNotNull($event->fresh()->processed_at);
    }

    private function connection(): ClientAccountShopifyConnection
    {
        return ClientAccountShopifyConnection::query()->create([
            'client_account_id' => ClientAccount::query()->create([
                'company_name' => 'Shopify Order Co',
                'status' => ClientAccount::STATUS_ACTIVE,
            ])->id,
            'shop_domain' => 'test.myshopify.com',
            'admin_api_access_token' => 'shpat_test',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function orderNode(array $lines): array
    {
        $edges = [];
        foreach ($lines as $line) {
            $edges[] = [
                'node' => array_merge([
                    'sku' => 'SKU',
                    'title' => 'Item',
                    'variantTitle' => '',
                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '10.00']],
                ], $line),
            ];
        }

        return [
            'id' => 'gid://shopify/Order/99',
            'name' => '#1099',
            'email' => 'buyer@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'currencyCode' => 'USD',
            'totalPriceSet' => ['shopMoney' => ['amount' => '10.00']],
            'lineItems' => ['edges' => $edges],
            'fulfillmentOrders' => ['edges' => []],
        ];
    }
}
