<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\Role;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderActivity;
use App\Models\ShopifyOrderLineItem;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Models\User;
use App\Services\ShopifyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShopifyOrderDetailApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['client_account_id' => null, 'name' => 'Michael Lee']);
        $admin = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );
        $user->roles()->attach($admin->id);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array{0: ClientAccount, 1: ClientAccountShopifyConnection, 2: ShopifyOrder}
     */
    private function seedOrder(array $overrides = []): array
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Detail Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
        $connection = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => $account->id,
            'shop_domain' => 'detail.myshopify.com',
            'admin_api_access_token' => 'shpat_test',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);
        $order = ShopifyOrder::query()->create(array_merge([
            'connection_id' => $connection->id,
            'shopify_order_id' => '1008',
            'name' => '#1008',
            'email' => 'buyer@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'currency' => 'USD',
            'total_price' => 40.00,
            'shopify_created_at' => now()->subHour(),
            'shipping_address_json' => [
                'name' => 'James Anderson',
                'address1' => '7421 Innovation Drive',
                'city' => 'Lakeland',
                'province' => 'FL',
                'zip' => '33809',
                'country' => 'United States',
                'phone' => '(863) 555-7842',
            ],
            'raw_json' => [
                'shippingLine' => [
                    'title' => 'UPS Ground',
                    'code' => 'UPS Ground',
                    'source' => 'UPS',
                    'originalPriceSet' => ['shopMoney' => ['amount' => '9.54', 'currencyCode' => 'USD']],
                ],
            ],
        ], $overrides));

        ShopifyOrderLineItem::query()->create([
            'connection_id' => $connection->id,
            'shopify_order_id' => $order->id,
            'shopify_line_item_id' => '11',
            'shopify_variant_id' => '99',
            'sku' => 'NC-HP100-BLK',
            'title' => 'Noise Cancelling Headphones',
            'quantity' => 1,
            'fulfillable_quantity' => 1,
            'fulfilled_quantity' => 0,
            'price' => 25.00,
        ]);

        return [$account, $connection, $order];
    }

    public function test_orders_show_returns_enriched_detail_and_timeline(): void
    {
        $this->actingAsAdmin();
        [, $connection, $order] = $this->seedOrder();

        $product = ShopifyProduct::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => '88',
            'title' => 'Headphones',
            'status' => 'active',
        ]);
        $variant = ShopifyProductVariant::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => $product->id,
            'shopify_variant_id' => '99',
            'sku' => 'NC-HP100-BLK',
            'title' => 'Default',
        ]);

        ShopifyOrderActivity::query()->create([
            'shopify_order_id' => $order->id,
            'type' => ShopifyOrderActivity::TYPE_IMPORTED,
            'title' => 'Order imported from Shopify',
            'actor_label' => 'System',
        ]);

        $this->getJson('/api/shopify/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('order.shipping.carrier', 'UPS')
            ->assertJsonPath('order.shipping.service', 'UPS Ground')
            ->assertJsonPath('order.recipient.name', 'James Anderson')
            ->assertJsonPath('order.line_items.0.crm_variant_id', $variant->id)
            ->assertJsonPath('order.line_items.0.line_status', 'pending')
            ->assertJsonPath('order.timeline.0.actor_label', 'System');
    }

    public function test_update_shipping_address_persists_and_logs_timeline(): void
    {
        $user = $this->actingAsAdmin();
        [, , $order] = $this->seedOrder();

        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldReceive('forConnection')->andReturnSelf();
        $client->shouldReceive('graphql')->once()->andReturn([
            'orderUpdate' => [
                'order' => ['id' => 'gid://shopify/Order/1008'],
                'userErrors' => [],
            ],
        ]);
        $this->app->instance(ShopifyClient::class, $client);

        $sync = Mockery::mock(\App\Services\ShopifyOrderSyncService::class);
        $sync->shouldReceive('refreshOrderByShopifyId')->andReturn($order->fresh());
        $this->app->instance(\App\Services\ShopifyOrderSyncService::class, $sync);

        $this->postJson('/api/shopify/orders/'.$order->id.'/shipping-address', [
            'full_name' => 'James Anderson',
            'address1' => '100 New Street',
            'address2' => '',
            'city' => 'Lakeland',
            'province' => 'FL',
            'zip' => '33809',
            'country' => 'United States',
            'email' => 'james@example.com',
            'phone' => '8635550000',
        ])
            ->assertOk()
            ->assertJsonPath('order.recipient.address1', '100 New Street');

        $this->assertDatabaseHas('shopify_order_activities', [
            'shopify_order_id' => $order->id,
            'type' => ShopifyOrderActivity::TYPE_ADDRESS,
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_update_shipping_method_sets_title_from_carrier_service(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder();

        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldReceive('forConnection')->andReturnSelf();
        $client->shouldReceive('graphql')->times(3)->andReturn(
            [
                'orderEditBegin' => [
                    'calculatedOrder' => ['id' => 'gid://shopify/CalculatedOrder/1'],
                    'userErrors' => [],
                ],
            ],
            [
                'orderEditAddShippingLine' => [
                    'calculatedOrder' => ['id' => 'gid://shopify/CalculatedOrder/1'],
                    'userErrors' => [],
                ],
            ],
            [
                'orderEditCommit' => [
                    'order' => ['id' => 'gid://shopify/Order/1008'],
                    'userErrors' => [],
                ],
            ]
        );
        $this->app->instance(ShopifyClient::class, $client);

        $sync = Mockery::mock(\App\Services\ShopifyOrderSyncService::class);
        $sync->shouldReceive('refreshOrderByShopifyId')->andReturn($order->fresh());
        $this->app->instance(\App\Services\ShopifyOrderSyncService::class, $sync);

        $this->postJson('/api/shopify/orders/'.$order->id.'/shipping-method', [
            'carrier' => 'UPS',
            'service' => 'UPS Ground',
            'price' => 9.54,
        ])
            ->assertOk()
            ->assertJsonPath('order.shipping.requested', 'UPS Ground');
    }

    public function test_update_items_qty_runs_order_edit_sequence(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder();
        $line = $order->lineItems()->first();

        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldReceive('forConnection')->andReturnSelf();
        $client->shouldReceive('graphql')->times(3)->andReturn(
            [
                'orderEditBegin' => [
                    'calculatedOrder' => [
                        'id' => 'gid://shopify/CalculatedOrder/1',
                        'lineItems' => [
                            'edges' => [[
                                'node' => [
                                    'id' => 'gid://shopify/CalculatedLineItem/55',
                                    'quantity' => 1,
                                    'variant' => ['id' => 'gid://shopify/ProductVariant/99'],
                                ],
                            ]],
                        ],
                    ],
                    'userErrors' => [],
                ],
            ],
            [
                'orderEditSetQuantity' => [
                    'calculatedOrder' => ['id' => 'gid://shopify/CalculatedOrder/1'],
                    'userErrors' => [],
                ],
            ],
            [
                'orderEditCommit' => [
                    'order' => ['id' => 'gid://shopify/Order/1008'],
                    'userErrors' => [],
                ],
            ]
        );
        $this->app->instance(ShopifyClient::class, $client);

        $sync = Mockery::mock(\App\Services\ShopifyOrderSyncService::class);
        $sync->shouldReceive('refreshOrderByShopifyId')->andReturn($order->fresh(['lineItems']));
        $this->app->instance(\App\Services\ShopifyOrderSyncService::class, $sync);

        $this->postJson('/api/shopify/orders/'.$order->id.'/items', [
            'lines' => [
                ['id' => $line->id, 'quantity' => 2],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('shopify_order_activities', [
            'shopify_order_id' => $order->id,
            'type' => ShopifyOrderActivity::TYPE_ITEMS,
        ]);
    }
}
