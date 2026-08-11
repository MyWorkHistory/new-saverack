<?php

namespace Tests\Feature;

use App\Jobs\ProcessShopifyWebhookJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopifyWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_invalid_hmac(): void
    {
        config(['services.shopify.webhook_secret' => 'secret']);

        $this->call(
            'POST',
            '/api/shopify/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SHOPIFY_HMAC_SHA256' => 'invalid',
                'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
                'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'test.myshopify.com',
            ],
            '{"id":1}'
        )->assertUnauthorized();
    }

    public function test_webhook_accepts_valid_hmac_and_queues_job(): void
    {
        Bus::fake();
        config(['services.shopify.webhook_secret' => 'secret']);

        ClientAccountShopifyConnection::query()->create([
            'client_account_id' => ClientAccount::query()->create([
                'company_name' => 'SH Co',
                'status' => ClientAccount::STATUS_ACTIVE,
            ])->id,
            'shop_domain' => 'test.myshopify.com',
            'admin_api_access_token' => 'shpat_x',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);

        $body = '{"id":55,"name":"#1001"}';
        $hmac = base64_encode(hash_hmac('sha256', $body, 'secret', true));

        $this->call(
            'POST',
            '/api/shopify/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
                'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
                'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'test.myshopify.com',
                'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh-1',
            ],
            $body
        )->assertOk()->assertJsonPath('ok', true);

        Bus::assertDispatched(ProcessShopifyWebhookJob::class);
        $this->assertDatabaseHas('shopify_webhook_events', [
            'event_id' => 'wh-1',
            'topic' => 'orders/create',
        ]);
    }

    public function test_shopify_orders_require_admin(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/shopify/orders')->assertForbidden();
    }
}
