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
            ->assertJsonPath('data.0.id', $shipped->id);
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
            ]);
    }
}
