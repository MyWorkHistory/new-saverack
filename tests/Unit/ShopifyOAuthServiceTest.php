<?php

namespace Tests\Unit;

use App\Services\ShopifyOAuthService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyOAuthServiceTest extends TestCase
{
    private function service(): ShopifyOAuthService
    {
        config([
            'services.shopify.client_id' => 'cid',
            'services.shopify.client_secret' => 'csecret',
            'services.shopify.scopes' => 'read_orders',
            'services.shopify.oauth_redirect_uri' => 'https://app.saverack.com/api/shopify/oauth/callback',
        ]);

        return app(ShopifyOAuthService::class);
    }

    public function test_verify_callback_hmac(): void
    {
        $service = $this->service();
        $params = [
            'code' => 'abc',
            'shop' => 'test.myshopify.com',
            'state' => 'xyz',
            'timestamp' => '123',
        ];
        ksort($params);
        $message = 'code=abc&shop=test.myshopify.com&state=xyz&timestamp=123';
        $params['hmac'] = hash_hmac('sha256', $message, 'csecret');

        $this->assertTrue($service->verifyCallbackHmac($params));
        $params['hmac'] = 'nope';
        $this->assertFalse($service->verifyCallbackHmac($params));
    }

    public function test_exchange_code_posts_to_shopify(): void
    {
        $service = $this->service();
        Http::fake([
            'https://demo.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_x',
                'scope' => 'read_orders',
            ], 200),
        ]);

        $result = $service->exchangeCode('demo.myshopify.com', 'code-1');
        $this->assertSame('shpat_x', $result['access_token']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://demo.myshopify.com/admin/oauth/access_token'
                && $request['client_id'] === 'cid'
                && $request['client_secret'] === 'csecret'
                && $request['code'] === 'code-1';
        });
    }

    public function test_normalize_and_authorization_url(): void
    {
        $service = $this->service();
        $this->assertSame('foo.myshopify.com', $service->normalizeShopDomain('foo'));
        $url = $service->authorizationUrl('foo.myshopify.com', 'state-1');
        $this->assertStringStartsWith('https://foo.myshopify.com/admin/oauth/authorize?', $url);
        $this->assertStringContainsString('client_id=cid', $url);
        $this->assertStringContainsString('state=state-1', $url);
    }
}
