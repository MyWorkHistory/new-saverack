<?php

namespace Tests\Unit;

use App\Services\ShopifyOrderAdminLinkService;
use Tests\TestCase;

class ShopifyOrderAdminLinkServiceTest extends TestCase
{
    public function test_builds_url_from_myshopify_host_in_shop_name_without_database(): void
    {
        $service = new ShopifyOrderAdminLinkService();
        $url = $service->buildAdminUrl(1, [
            'partner_order_id' => '6916165566680',
            'account' => 'https://esas-beauty.myshopify.com',
        ]);

        $this->assertSame(
            'https://esas-beauty.myshopify.com/admin/orders/6916165566680',
            $url
        );
    }

    public function test_returns_null_when_partner_order_id_missing(): void
    {
        $service = new ShopifyOrderAdminLinkService();
        $url = $service->buildAdminUrl(1, [
            'partner_order_id' => '',
            'account' => 'esas-beauty.myshopify.com',
        ]);

        $this->assertNull($url);
    }

    public function test_returns_null_when_partner_order_id_not_numeric(): void
    {
        $service = new ShopifyOrderAdminLinkService();
        $url = $service->buildAdminUrl(1, [
            'partner_order_id' => 'abc',
            'account' => 'esas-beauty.myshopify.com',
        ]);

        $this->assertNull($url);
    }
}
