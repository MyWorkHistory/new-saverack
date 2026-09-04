<?php

namespace Tests\Unit;

use App\Jobs\ProcessShopifyWebhookJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyLocation;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderLineItem;
use App\Models\ShopifyWebhookEvent;
use App\Services\ShopifyOrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyOrderSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_order_name_adds_hash_prefix(): void
    {
        $service = app(ShopifyOrderSyncService::class);

        $this->assertSame('#1234', $service->normalizeOrderName('1234'));
        $this->assertSame('#1234', $service->normalizeOrderName('#1234'));
        $this->assertSame('#1234', $service->normalizeOrderName('  #1234  '));
        $this->assertSame('', $service->normalizeOrderName('   '));
    }

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

    public function test_upserts_rest_order_payload(): void
    {
        $connection = $this->connection();
        $service = app(ShopifyOrderSyncService::class);

        $ok = $service->upsertOrderFromShopifyNode($connection, [
            'id' => 1001,
            'admin_graphql_api_id' => 'gid://shopify/Order/1001',
            'name' => '#1001',
            'email' => 'buyer@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'currency' => 'USD',
            'total_price' => '25.00',
            'location_id' => 10,
            'line_items' => [
                [
                    'id' => 11,
                    'admin_graphql_api_id' => 'gid://shopify/LineItem/11',
                    'sku' => 'SKU-1',
                    'title' => 'Tee',
                    'variant_title' => 'M',
                    'quantity' => 2,
                    'fulfillable_quantity' => 2,
                    'price' => '12.50',
                    'variant_id' => 99,
                    'product_id' => 88,
                ],
            ],
        ]);

        $this->assertTrue($ok);
        $order = ShopifyOrder::query()->where('shopify_order_id', '1001')->first();
        $this->assertNotNull($order);
        $this->assertSame('#1001', $order->name);
        $line = ShopifyOrderLineItem::query()->where('shopify_order_id', $order->id)->first();
        $this->assertNotNull($line);
        $this->assertSame('SKU-1', $line->sku);
        $this->assertSame(2, (int) $line->quantity);
        $this->assertSame('99', (string) $line->shopify_variant_id);
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

    public function test_rest_shipping_lines_normalized_onto_shipping_line(): void
    {
        $connection = $this->connection();
        $service = app(ShopifyOrderSyncService::class);

        $ok = $service->upsertOrderFromShopifyNode($connection, [
            'id' => 2001,
            'admin_graphql_api_id' => 'gid://shopify/Order/2001',
            'name' => '#2001',
            'email' => 'ship@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'currency' => 'USD',
            'total_price' => '30.00',
            'location_id' => 10,
            'shipping_lines' => [
                ['title' => 'Economy', 'code' => 'ECONOMY'],
            ],
            'line_items' => [
                [
                    'id' => 21,
                    'admin_graphql_api_id' => 'gid://shopify/LineItem/21',
                    'sku' => 'SKU-S',
                    'title' => 'Sock',
                    'quantity' => 1,
                    'fulfillable_quantity' => 1,
                    'price' => '30.00',
                ],
            ],
        ]);

        $this->assertTrue($ok);
        $order = ShopifyOrder::query()->where('shopify_order_id', '2001')->first();
        $this->assertNotNull($order);
        $raw = is_array($order->raw_json) ? $order->raw_json : [];
        $this->assertSame('Economy', $raw['shippingLine']['title'] ?? null);
        $this->assertSame('Economy', app(\App\Services\ShopifyOrderListService::class)->shippingMethod($order));
    }

    public function test_graphql_refresh_without_shipping_preserves_prior_shipping_lines(): void
    {
        $connection = $this->connection();
        $service = app(ShopifyOrderSyncService::class);

        $service->upsertOrderFromShopifyNode($connection, [
            'id' => 2002,
            'name' => '#2002',
            'email' => 'keep@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'currency' => 'USD',
            'total_price' => '10.00',
            'location_id' => 10,
            'shipping_lines' => [
                ['title' => 'Standard Shipping'],
            ],
            'line_items' => [
                [
                    'id' => 31,
                    'sku' => 'SKU-K',
                    'title' => 'Item',
                    'quantity' => 1,
                    'fulfillable_quantity' => 1,
                    'price' => '10.00',
                ],
            ],
        ]);

        $service->upsertOrderFromShopifyNode($connection, [
            'id' => 'gid://shopify/Order/2002',
            'name' => '#2002',
            'email' => 'keep@example.com',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'currencyCode' => 'USD',
            'totalPriceSet' => ['shopMoney' => ['amount' => '10.00']],
            'lineItems' => ['edges' => [[
                'node' => [
                    'id' => 'gid://shopify/LineItem/31',
                    'sku' => 'SKU-K',
                    'title' => 'Item',
                    'quantity' => 1,
                    'currentQuantity' => 1,
                    'unfulfilledQuantity' => 1,
                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '10.00']],
                ],
            ]]],
            'fulfillmentOrders' => ['edges' => [[
                'node' => [
                    'id' => 'gid://shopify/FulfillmentOrder/22',
                    'assignedLocation' => [
                        'location' => ['id' => 'gid://shopify/Location/10'],
                    ],
                    'lineItems' => ['edges' => []],
                ],
            ]]],
        ]);

        $order = ShopifyOrder::query()->where('shopify_order_id', '2002')->first();
        $this->assertNotNull($order);
        $this->assertSame('Standard Shipping', app(\App\Services\ShopifyOrderListService::class)->shippingMethod($order));
    }

    public function test_upsert_fulfilled_clears_crm_hold_reasons(): void
    {
        $connection = $this->connection();
        $service = app(ShopifyOrderSyncService::class);

        $service->upsertOrderFromShopifyNode($connection, [
            'id' => 2003,
            'name' => '#2003',
            'email' => 'hold@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'currency' => 'USD',
            'total_price' => '10.00',
            'location_id' => 10,
            'line_items' => [
                [
                    'id' => 41,
                    'sku' => 'SKU-H',
                    'title' => 'Item',
                    'quantity' => 1,
                    'fulfillable_quantity' => 1,
                    'price' => '10.00',
                ],
            ],
        ]);

        $order = ShopifyOrder::query()->where('shopify_order_id', '2003')->first();
        $this->assertNotNull($order);
        $order->crm_hold_reasons = ['Admin Hold'];
        $order->save();

        $service->upsertOrderFromShopifyNode($connection, [
            'id' => 2003,
            'name' => '#2003',
            'email' => 'hold@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'currency' => 'USD',
            'total_price' => '10.00',
            'location_id' => 10,
            'line_items' => [
                [
                    'id' => 41,
                    'sku' => 'SKU-H',
                    'title' => 'Item',
                    'quantity' => 1,
                    'fulfillable_quantity' => 0,
                    'price' => '10.00',
                ],
            ],
        ]);

        $order = $order->fresh();
        $this->assertNotNull($order);
        $this->assertSame('fulfilled', $order->fulfillment_status);
        $this->assertSame([], $order->crm_hold_reasons ?? []);
    }

    private function connection(): ClientAccountShopifyConnection
    {
        $connection = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => ClientAccount::query()->create([
                'company_name' => 'Shopify Order Co',
                'status' => ClientAccount::STATUS_ACTIVE,
            ])->id,
            'shop_domain' => 'test.myshopify.com',
            'admin_api_access_token' => 'shpat_test',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);
        ShopifyLocation::query()->create([
            'connection_id' => $connection->id,
            'shopify_location_id' => '10',
            'name' => 'Warehouse',
            'import_orders' => true,
            'sync_inventory' => true,
        ]);

        return $connection;
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
            'fulfillmentOrders' => ['edges' => [[
                'node' => [
                    'id' => 'gid://shopify/FulfillmentOrder/1',
                    'assignedLocation' => [
                        'location' => ['id' => 'gid://shopify/Location/10'],
                    ],
                    'lineItems' => ['edges' => []],
                ],
            ]]],
        ];
    }
}
