<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\Role;
use App\Models\ShopifyOrder;
use App\Models\User;
use App\Services\ShopifyOrderActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShopifyOrderActionsApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
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
            'company_name' => 'Shopify Actions Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
        $connection = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => $account->id,
            'shop_domain' => 'actions.myshopify.com',
            'admin_api_access_token' => 'shpat_test',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);
        $order = ShopifyOrder::query()->create(array_merge([
            'connection_id' => $connection->id,
            'shopify_order_id' => '5001',
            'name' => '#5001',
            'email' => 'buyer@example.com',
            'financial_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'currency' => 'USD',
            'total_price' => 25.00,
            'shopify_created_at' => now()->subDay(),
            'shipping_address_json' => [
                'name' => 'Jane Buyer',
                'countryCodeV2' => 'US',
            ],
            'customer_json' => [
                'firstName' => 'Jane',
                'lastName' => 'Buyer',
            ],
            'raw_json' => [
                'shippingLine' => ['title' => 'endicia / MediaMail'],
            ],
        ], $overrides));

        return [$account, $connection, $order];
    }

    public function test_orders_index_returns_display_fields(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder();

        $this->getJson('/api/shopify/orders?q=Jane')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.display_status', 'ready_to_ship')
            ->assertJsonPath('data.0.recipient_name', 'Jane Buyer')
            ->assertJsonPath('data.0.country', 'US')
            ->assertJsonPath('data.0.shipping_method', 'endicia / MediaMail');
    }

    public function test_orders_index_filters_by_account_and_status(): void
    {
        $this->actingAsAdmin();
        [, , $held] = $this->seedOrder([
            'name' => '#held',
            'crm_hold_reasons' => ['Admin Hold'],
            'shipping_address_json' => ['name' => 'Held User', 'countryCodeV2' => 'CA'],
        ]);
        [, , $shipped] = $this->seedOrder([
            'name' => '#shipped',
            'fulfillment_status' => 'fulfilled',
            'shipping_address_json' => ['name' => 'Shipped User', 'countryCodeV2' => 'US'],
        ]);

        $held->load('connection');
        $accountId = (int) $held->connection->client_account_id;

        $this->getJson('/api/shopify/orders?status=on_hold&client_account_id='.$accountId)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $held->id);

        $this->getJson('/api/shopify/orders?status=shipped')
            ->assertOk()
            ->assertJsonPath('data.0.id', $shipped->id)
            ->assertJsonPath('data.0.display_status', 'fulfilled');
    }

    public function test_orders_export_returns_csv_headers(): void
    {
        $this->actingAsAdmin();
        $this->seedOrder();

        $response = $this->get('/api/shopify/orders/export');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Status,Order #,Recipient', $response->streamedContent());
    }

    public function test_hold_persists_even_when_shopify_tag_sync_fails(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder();

        $api = Mockery::mock(\App\Services\ShopifyClient::class);
        $api->shouldReceive('graphql')
            ->once()
            ->andThrow(new \RuntimeException('Order does not exist'));

        $client = Mockery::mock(\App\Services\ShopifyClient::class);
        $client->shouldReceive('forConnection')->andReturn($api);
        $this->app->instance(\App\Services\ShopifyClient::class, $client);
        $this->app->forgetInstance(ShopifyOrderActionService::class);

        $this->postJson('/api/shopify/orders/'.$order->id.'/hold', [
            'reasons' => ['Admin Hold'],
        ])
            ->assertOk()
            ->assertJsonPath('order.display_status', 'on_hold');

        $order->refresh();
        $this->assertSame(['Admin Hold'], $order->crm_hold_reasons);
    }

    public function test_hold_persists_crm_hold_reasons(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder();

        $mock = Mockery::mock(ShopifyOrderActionService::class);
        $mock->shouldReceive('holdOrder')
            ->once()
            ->andReturnUsing(function (ShopifyOrder $o, array $reasons) {
                $o->crm_hold_reasons = $reasons;
                $o->save();

                return $o->fresh(['connection.clientAccount']);
            });
        $this->app->instance(ShopifyOrderActionService::class, $mock);

        $this->postJson('/api/shopify/orders/'.$order->id.'/hold', [
            'reasons' => ['Admin Hold', 'Payment Hold'],
        ])
            ->assertOk()
            ->assertJsonPath('order.display_status', 'on_hold');

        $order->refresh();
        $this->assertSame(['Admin Hold', 'Payment Hold'], $order->crm_hold_reasons);
    }

    public function test_bulk_endpoints_validate_ids(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/shopify/orders/bulk/cancel', ['ids' => []])
            ->assertStatus(422);

        $this->postJson('/api/shopify/orders/bulk/fulfill', ['ids' => []])
            ->assertStatus(422);

        $this->postJson('/api/shopify/orders/bulk/hold', [
            'ids' => [],
            'reasons' => ['Admin Hold'],
        ])->assertStatus(422);
    }

    public function test_orders_meta_includes_filter_options(): void
    {
        $this->actingAsAdmin();
        $this->seedOrder();

        $this->getJson('/api/shopify/orders/meta')
            ->assertOk()
            ->assertJsonStructure([
                'countries',
                'shipping_methods',
                'statuses',
                'hold_reasons',
                'accounts',
            ])
            ->assertJsonFragment(['value' => 'fulfilled', 'label' => 'Fulfilled']);
    }

    public function test_fulfilled_order_cannot_be_held(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder(['fulfillment_status' => 'fulfilled']);

        $this->postJson('/api/shopify/orders/'.$order->id.'/hold', [
            'reasons' => ['Admin Hold'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change fulfilled order status.');
    }

    public function test_bulk_hold_rejects_fulfilled_orders(): void
    {
        $this->actingAsAdmin();
        [, , $ready] = $this->seedOrder(['name' => '#ready']);
        [, , $fulfilled] = $this->seedOrder([
            'name' => '#done',
            'shopify_order_id' => '5002',
            'fulfillment_status' => 'fulfilled',
        ]);

        $mock = Mockery::mock(ShopifyOrderActionService::class);
        $mock->shouldReceive('holdOrder')
            ->andReturnUsing(function (ShopifyOrder $o, array $reasons) {
                if (strtolower((string) $o->fulfillment_status) === 'fulfilled') {
                    throw new \RuntimeException('Cannot change fulfilled order status.');
                }
                $o->crm_hold_reasons = $reasons;
                $o->save();

                return $o->fresh(['connection.clientAccount']);
            });
        $this->app->instance(ShopifyOrderActionService::class, $mock);

        $this->postJson('/api/shopify/orders/bulk/hold', [
            'ids' => [$ready->id, $fulfilled->id],
            'reasons' => ['Admin Hold'],
        ])
            ->assertOk()
            ->assertJsonPath('updated.0.id', $ready->id)
            ->assertJsonPath('errors.0.id', $fulfilled->id)
            ->assertJsonPath('errors.0.message', 'Cannot change fulfilled order status.');
    }

    public function test_crm_only_cancel_does_not_call_shopify_cancel(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder();

        $mock = Mockery::mock(ShopifyOrderActionService::class);
        $mock->shouldReceive('cancelOrder')
            ->once()
            ->with(Mockery::on(fn ($o) => (int) $o->id === (int) $order->id), false)
            ->andReturnUsing(function (ShopifyOrder $o) {
                $o->crm_fulfillment_cancelled_at = now();
                $o->save();

                return $o->fresh(['connection.clientAccount']);
            });
        $this->app->instance(ShopifyOrderActionService::class, $mock);

        $this->postJson('/api/shopify/orders/'.$order->id.'/cancel', [
            'cancel_in_shopify' => false,
        ])
            ->assertOk()
            ->assertJsonPath('order.display_status', 'cancelled');

        $order->refresh();
        $this->assertNotNull($order->crm_fulfillment_cancelled_at);
    }

    public function test_crm_cancel_via_real_service_sets_cancelled_display_status(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder();

        $this->postJson('/api/shopify/orders/'.$order->id.'/cancel', [
            'cancel_in_shopify' => false,
        ])
            ->assertOk()
            ->assertJsonPath('order.display_status', 'cancelled');

        $order->refresh();
        $this->assertNotNull($order->crm_fulfillment_cancelled_at);
        $this->assertNull($order->cancelled_at);
    }

    public function test_orders_meta_includes_cancelled_status(): void
    {
        $this->actingAsAdmin();
        $this->seedOrder();

        $this->getJson('/api/shopify/orders/meta')
            ->assertOk()
            ->assertJsonFragment(['value' => 'cancelled', 'label' => 'Cancelled']);
    }

    public function test_shopify_cancel_uses_refund_method_payload(): void
    {
        $this->actingAsAdmin();
        [, $connection, $order] = $this->seedOrder();

        $api = Mockery::mock(\App\Services\ShopifyClient::class);
        $api->shouldReceive('graphql')
            ->once()
            ->withArgs(function (string $query, array $vars) {
                return str_contains($query, 'refundMethod')
                    && str_contains($query, 'orderCancelUserErrors')
                    && str_contains($query, 'job { id done }')
                    && ! str_contains($query, 'order { id cancelledAt }')
                    && ($vars['reason'] ?? null) === 'OTHER'
                    && ($vars['refundMethod']['originalPaymentMethodsRefund'] ?? null) === false
                    && ($vars['restock'] ?? null) === true;
            })
            ->andReturn([
                'orderCancel' => [
                    'job' => ['id' => 'gid://shopify/Job/1', 'done' => true],
                    'orderCancelUserErrors' => [],
                    'userErrors' => [],
                ],
            ]);

        $client = Mockery::mock(\App\Services\ShopifyClient::class);
        $client->shouldReceive('forConnection')->andReturn($api);
        $this->app->instance(\App\Services\ShopifyClient::class, $client);

        $sync = Mockery::mock(\App\Services\ShopifyOrderSyncService::class);
        $sync->shouldReceive('refreshOrderByShopifyId')
            ->atLeast()
            ->once()
            ->andReturnUsing(function () use ($order) {
                $order->cancelled_at = now();
                $order->save();

                return $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']);
            });
        $this->app->instance(\App\Services\ShopifyOrderSyncService::class, $sync);
        $this->app->forgetInstance(ShopifyOrderActionService::class);

        $this->postJson('/api/shopify/orders/'.$order->id.'/cancel', [
            'cancel_in_shopify' => true,
        ])
            ->assertOk()
            ->assertJsonPath('order.display_status', 'cancelled');

        $this->assertNotNull($connection->fresh());
    }

    public function test_fulfill_all_rejected_for_cancelled_order(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder([
            'crm_fulfillment_cancelled_at' => now(),
        ]);

        $this->postJson('/api/shopify/orders/'.$order->id.'/fulfill-all')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot fulfill a cancelled order.');
    }

    public function test_fulfill_all_rejects_when_no_fo_lines_after_sync(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder();

        $sync = Mockery::mock(\App\Services\ShopifyOrderSyncService::class);
        $sync->shouldReceive('refreshOrderByShopifyId')
            ->once()
            ->andReturn($order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']));
        $sync->shouldReceive('syncFulfillmentOrdersFromRestApi')
            ->once()
            ->andReturn(0);
        $this->app->instance(\App\Services\ShopifyOrderSyncService::class, $sync);
        $this->app->forgetInstance(ShopifyOrderActionService::class);

        $this->postJson('/api/shopify/orders/'.$order->id.'/fulfill-all')
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'No fulfillable quantities remain on this order. Sync the order from Shopify and try again.'
            );
    }

    public function test_fulfill_loads_fo_lines_from_rest_when_missing(): void
    {
        $this->actingAsAdmin();
        [, $connection, $order] = $this->seedOrder();

        $line = \App\Models\ShopifyOrderLineItem::query()->create([
            'connection_id' => $connection->id,
            'shopify_order_id' => $order->id,
            'shopify_line_item_id' => '9001',
            'sku' => 'SKU-1',
            'title' => 'Widget',
            'quantity' => 2,
            'fulfillable_quantity' => 2,
            'fulfilled_quantity' => 0,
            'price' => 10,
        ]);

        $sync = Mockery::mock(\App\Services\ShopifyOrderSyncService::class);
        $sync->shouldReceive('refreshOrderByShopifyId')->andReturn($order->fresh());
        $sync->shouldReceive('syncFulfillmentOrdersFromRestApi')
            ->once()
            ->andReturnUsing(function () use ($connection, $order, $line) {
                $fo = \App\Models\ShopifyFulfillmentOrder::query()->create([
                    'connection_id' => $connection->id,
                    'shopify_order_id' => $order->id,
                    'shopify_fulfillment_order_id' => '7001',
                    'status' => 'open',
                ]);
                \App\Models\ShopifyFulfillmentOrderLineItem::query()->create([
                    'connection_id' => $connection->id,
                    'shopify_fulfillment_order_id' => $fo->id,
                    'shopify_order_line_item_id' => $line->id,
                    'shopify_fo_line_item_id' => '8001',
                    'shopify_line_item_id' => '9001',
                    'total_quantity' => 2,
                    'remaining_quantity' => 2,
                ]);

                return 1;
            });
        $this->app->instance(\App\Services\ShopifyOrderSyncService::class, $sync);

        $fulfillments = Mockery::mock(\App\Services\ShopifyFulfillmentService::class);
        $fulfillments->shouldReceive('markShipped')
            ->once()
            ->andReturnUsing(function (ShopifyOrder $o) {
                $o->fulfillment_status = 'fulfilled';
                $o->save();

                return [
                    'fulfillment' => null,
                    'order' => $o->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']),
                ];
            });
        $this->app->instance(\App\Services\ShopifyFulfillmentService::class, $fulfillments);
        $this->app->forgetInstance(ShopifyOrderActionService::class);

        $this->postJson('/api/shopify/orders/'.$order->id.'/fulfill-all')
            ->assertOk()
            ->assertJsonPath('order.display_status', 'fulfilled');
    }

    public function test_export_uses_blank_cells_and_us_date(): void
    {
        $this->actingAsAdmin();
        $this->seedOrder([
            'name' => '#1001',
            'fulfillment_status' => 'fulfilled',
            'shopify_created_at' => '2026-08-18 15:20:18',
            'shipping_address_json' => [],
            'customer_json' => [],
            'email' => null,
            'raw_json' => [],
        ]);

        $csv = $this->get('/api/shopify/orders/export')->streamedContent();
        $this->assertStringContainsString('Fulfilled', $csv);
        $this->assertStringContainsString('1001', $csv);
        $this->assertStringNotContainsString('#1001', $csv);
        $this->assertStringContainsString('08-18-2026', $csv);
        $this->assertStringNotContainsString('—', $csv);
    }

    public function test_reprocess_rejected_for_fulfilled_order(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder(['fulfillment_status' => 'fulfilled']);

        $this->postJson('/api/shopify/orders/'.$order->id.'/reprocess')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change fulfilled order status.');
    }

    public function test_reship_rejected_when_not_fulfilled(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder();

        $this->postJson('/api/shopify/orders/'.$order->id.'/reship', [
            'line_item_ids' => [1],
        ])
            ->assertStatus(422);
    }

    public function test_fulfill_all_rejected_for_already_fulfilled_order(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder(['fulfillment_status' => 'fulfilled']);

        $this->postJson('/api/shopify/orders/'.$order->id.'/fulfill-all')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change fulfilled order status.');
    }

    public function test_display_status_ready_clears_hold(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder(['crm_hold_reasons' => ['Admin Hold']]);

        $this->postJson('/api/shopify/orders/'.$order->id.'/display-status', [
            'status' => 'ready_to_ship',
        ])
            ->assertOk()
            ->assertJsonPath('order.display_status', 'ready_to_ship');
    }

    public function test_display_status_ready_clears_sticky_backorder_hint(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder([
            'raw_json' => [
                'shippingLine' => ['title' => 'endicia / MediaMail'],
                'crm_display_hint' => 'backorder',
                // Noise that previously kept status stuck on Backorder via stripos on full JSON.
                'note' => 'Customer asked about backorder policy',
            ],
        ]);

        $this->getJson('/api/shopify/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('order.display_status', 'backorder');

        $this->postJson('/api/shopify/orders/'.$order->id.'/display-status', [
            'status' => 'ready_to_ship',
        ])
            ->assertOk()
            ->assertJsonPath('order.display_status', 'ready_to_ship');
    }

    public function test_display_status_backorder_sets_hint(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder();

        $this->postJson('/api/shopify/orders/'.$order->id.'/display-status', [
            'status' => 'backorder',
        ])
            ->assertOk()
            ->assertJsonPath('order.display_status', 'backorder');
    }

    public function test_crm_cancelled_can_recover_to_ready(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder([
            'crm_fulfillment_cancelled_at' => now(),
        ]);

        $this->postJson('/api/shopify/orders/'.$order->id.'/display-status', [
            'status' => 'ready_to_ship',
        ])
            ->assertOk()
            ->assertJsonPath('order.display_status', 'ready_to_ship');
    }

    public function test_shopify_cancelled_cannot_change_display_status(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder([
            'cancelled_at' => now(),
        ]);

        $this->postJson('/api/shopify/orders/'.$order->id.'/display-status', [
            'status' => 'ready_to_ship',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change cancelled order status.');
    }

    public function test_shipping_method_from_rest_shipping_lines(): void
    {
        $this->actingAsAdmin();
        [, , $order] = $this->seedOrder([
            'raw_json' => [
                'shipping_lines' => [
                    ['title' => 'Express Overnight', 'code' => 'EXPRESS'],
                ],
            ],
        ]);

        $this->getJson('/api/shopify/orders?q=Jane')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.shipping_method', 'Express Overnight');

        $this->getJson('/api/shopify/orders?shipping_method=Express')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id);
    }

    public function test_on_hold_filter_excludes_fulfilled_orders_with_stale_holds(): void
    {
        $this->actingAsAdmin();
        [, , $held] = $this->seedOrder([
            'name' => '#still-held',
            'crm_hold_reasons' => ['Admin Hold'],
            'fulfillment_status' => 'unfulfilled',
        ]);
        $this->seedOrder([
            'name' => '#fulfilled-with-stale-hold',
            'crm_hold_reasons' => ['Admin Hold'],
            'fulfillment_status' => 'fulfilled',
        ]);

        $this->getJson('/api/shopify/orders?status=on_hold')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $held->id);
    }
}
