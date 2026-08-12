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
        $this->assertSame('103507198114', ShopifyGid::toId(
            'gid://shopify/InventoryLevel/103507198114?inventory_item_id=48365093355682'
        ));
        $this->assertSame('gid://shopify/Order/99', ShopifyGid::of('Order', 99));
    }

    public function test_inventory_webhook_ids_prefer_gid_query_and_reject_float_precision_loss(): void
    {
        $this->assertSame('48365093355682', ShopifyGid::inventoryItemIdFromPayload([
            'inventory_item_id' => 48365093355682.0, // corrupted float — ignore
            'admin_graphql_api_id' => 'gid://shopify/InventoryLevel/103507198114?inventory_item_id=48365093355682',
            'location_id' => '69128716450',
            'available' => 32,
        ]));

        $this->assertSame('48365093355682', ShopifyGid::inventoryItemIdFromPayload([
            'inventory_item_id' => '48365093355682',
            'location_id' => 69128716450,
        ]));

        $this->assertSame('', ShopifyGid::numericIdString(4.8365093355682E+13));
        $this->assertSame('69128716450', ShopifyGid::locationIdFromPayload([
            'location_id' => '69128716450',
        ]));
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

    public function test_shop_domain_aliases_match_renamed_myshopify_host(): void
    {
        $connection = new \App\Models\ClientAccountShopifyConnection([
            'shop_domain' => 'save-rack-2.myshopify.com',
            'shop_domain_aliases' => ['1gwr02-06.myshopify.com'],
        ]);

        $this->assertTrue($connection->matchesShopDomain('1gwr02-06.myshopify.com'));
        $this->assertTrue($connection->matchesShopDomain('save-rack-2.myshopify.com'));
        $this->assertFalse($connection->matchesShopDomain('other.myshopify.com'));
        $this->assertSame(
            ['save-rack-2.myshopify.com', '1gwr02-06.myshopify.com'],
            $connection->allShopDomains()
        );
    }
}
