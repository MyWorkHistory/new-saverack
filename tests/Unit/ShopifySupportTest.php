<?php

namespace Tests\Unit;

use App\Services\ShopifyOrderSyncService;
use App\Services\ShopifyWebhookVerifier;
use App\Support\ShopifyGid;
use Tests\TestCase;

class ShopifySupportTest extends TestCase
{
    public function test_gid_to_id_and_of(): void
    {
        $this->assertSame('123', ShopifyGid::toId('gid://shopify/Product/123'));
        $this->assertSame('123', ShopifyGid::toId('123'));
        $this->assertSame('gid://shopify/Order/99', ShopifyGid::of('Order', 99));
    }

    public function test_webhook_hmac_verify(): void
    {
        $verifier = new ShopifyWebhookVerifier();
        $body = '{"id":1}';
        $secret = 'test-secret';
        $hmac = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $this->assertTrue($verifier->verify($body, $hmac, $secret));
        $this->assertFalse($verifier->verify($body, 'bad', $secret));
        $this->assertFalse($verifier->verify($body, $hmac, ''));
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

        $this->assertTrue($service->topicLooksLikeDelete('orders/delete'));
        $this->assertTrue($service->topicLooksLikeDelete('orders_delete'));
        $this->assertFalse($service->topicLooksLikeDelete('orders/edited'));
        $this->assertFalse($service->topicLooksLikeDelete('orders/updated'));
    }
}
