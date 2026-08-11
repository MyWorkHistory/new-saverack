<?php

namespace Tests\Unit;

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
}
