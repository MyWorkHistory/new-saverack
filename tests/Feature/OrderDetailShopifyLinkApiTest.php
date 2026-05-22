<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientStore;
use App\Models\Permission;
use App\Models\User;
use App\Services\ShipHeroOrderService;
use App\Services\ShopifyOrderAdminLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class OrderDetailShopifyLinkApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function inventoryViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'View inventory', 'module' => 'inventory']
        );
    }

    private function makeAccountWithShipHero(): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'Shopify Link Test Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-shopify-link-1',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalOrderPayload(): array
    {
        return [
            'id' => 'T3JkZXI6MTIz',
            'legacy_id' => 1,
            'order_number' => '82373',
            'partner_order_id' => '6916165566680',
            'source' => 'shopify',
            'status' => 'pending',
            'hold_reason' => null,
            'holds' => [
                'fraud_hold' => false,
                'address_hold' => false,
                'shipping_method_hold' => false,
                'operator_hold' => false,
                'payment_hold' => false,
                'client_hold' => false,
            ],
            'has_active_hold' => false,
            'not_ready_subtitle' => '',
            'order_date' => null,
            'required_ship_date' => null,
            'account' => 'Esas Beauty',
            'email' => 'test@example.test',
            'shipping_carrier' => '',
            'method' => '',
            'shipping_line' => ['title' => 'Shipping', 'carrier' => '', 'method' => '', 'price' => '0'],
            'shipping_cost' => null,
            'subtotal' => null,
            'total_tax' => null,
            'total_discounts' => null,
            'total_price' => null,
            'gift_invoice' => false,
            'allow_partial' => false,
            'require_signature' => false,
            'packing_note' => null,
            'gift_note' => '',
            'tags' => [],
            'attachments' => [],
            'shipping_address' => [],
            'billing_address' => null,
            'items' => [],
            'history' => [],
        ];
    }

    public function test_order_detail_includes_shopify_admin_url_for_shopify_store(): void
    {
        $account = $this->makeAccountWithShipHero();
        ClientStore::query()->create([
            'client_account_id' => $account->id,
            'status' => ClientStore::STATUS_ACTIVE,
            'name' => 'Esas Beauty',
            'website' => 'esas-beauty.myshopify.com',
            'marketplace' => 'Shopify',
        ]);

        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('getOrder')
            ->once()
            ->andReturn($this->minimalOrderPayload());
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $response = $this->getJson(
            '/api/orders/T3JkZXI6MTIz?client_account_id='.$account->id
        );

        $response->assertOk()
            ->assertJsonPath(
                'order.shopify_admin_url',
                'https://esas-beauty.myshopify.com/admin/orders/6916165566680'
            )
            ->assertJsonPath('order.source', 'shopify');
    }

    public function test_order_detail_omits_shopify_admin_url_for_non_shopify_store(): void
    {
        $account = $this->makeAccountWithShipHero();
        ClientStore::query()->create([
            'client_account_id' => $account->id,
            'status' => ClientStore::STATUS_ACTIVE,
            'name' => 'Amazon Store',
            'website' => 'https://amazon.example.test',
            'marketplace' => 'Amazon',
        ]);

        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('getOrder')
            ->once()
            ->andReturn($this->minimalOrderPayload());
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $response = $this->getJson(
            '/api/orders/T3JkZXI6MTIz?client_account_id='.$account->id
        );

        $response->assertOk()
            ->assertJsonMissingPath('order.shopify_admin_url');
    }

    public function test_shopify_link_service_builds_url_from_single_shopify_store(): void
    {
        $account = $this->makeAccountWithShipHero();
        ClientStore::query()->create([
            'client_account_id' => $account->id,
            'status' => ClientStore::STATUS_ACTIVE,
            'name' => 'Other Name',
            'website' => 'https://esas-beauty.myshopify.com',
            'marketplace' => 'Shopify',
        ]);

        $service = new ShopifyOrderAdminLinkService();
        $url = $service->buildAdminUrl($account->id, [
            'partner_order_id' => '6916165566680',
            'account' => 'Unrelated Shop Label',
        ]);

        $this->assertSame(
            'https://esas-beauty.myshopify.com/admin/orders/6916165566680',
            $url
        );
    }
}
