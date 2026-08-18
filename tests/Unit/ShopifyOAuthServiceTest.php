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
        $this->assertSame('read_orders', $result['scope']);
        $this->assertNull($result['refresh_token']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://demo.myshopify.com/admin/oauth/access_token'
                && $request['client_id'] === 'cid'
                && $request['client_secret'] === 'csecret'
                && $request['code'] === 'code-1'
                && (string) $request['expiring'] === '1';
        });
    }

    public function test_exchange_code_stores_expiring_refresh_token(): void
    {
        $service = $this->service();
        Http::fake([
            'https://demo.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_exp',
                'scope' => 'read_orders',
                'expires_in' => 3600,
                'refresh_token' => 'shprt_exp',
                'refresh_token_expires_in' => 7776000,
            ], 200),
        ]);

        $result = $service->exchangeCode('demo.myshopify.com', 'code-1');
        $this->assertSame('shpat_exp', $result['access_token']);
        $this->assertSame(3600, $result['expires_in']);
        $this->assertSame('shprt_exp', $result['refresh_token']);
        $this->assertSame(7776000, $result['refresh_token_expires_in']);
    }

    public function test_refresh_offline_token_posts_grant(): void
    {
        $service = $this->service();
        Http::fake([
            'https://demo.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_new',
                'expires_in' => 3600,
                'refresh_token' => 'shprt_new',
                'refresh_token_expires_in' => 7776000,
            ], 200),
        ]);

        $result = $service->refreshOfflineToken('demo.myshopify.com', 'shprt_old');
        $this->assertSame('shpat_new', $result['access_token']);
        $this->assertSame('shprt_new', $result['refresh_token']);

        Http::assertSent(function ($request) {
            return $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'shprt_old';
        });
    }

    public function test_is_non_expiring_token_rejection(): void
    {
        $service = $this->service();
        $this->assertTrue($service->isNonExpiringTokenRejection(
            'Shopify GraphQL HTTP error: [API] Non-expiring access tokens are no longer accepted for the Admin API'
        ));
        $this->assertFalse($service->isNonExpiringTokenRejection('HTTP 403'));
    }

    public function test_normalize_and_authorization_url(): void
    {
        $service = $this->service();
        $this->assertSame('foo.myshopify.com', $service->normalizeShopDomain('foo'));
        $url = $service->authorizationUrl('foo.myshopify.com', 'state-1');
        $this->assertStringStartsWith('https://foo.myshopify.com/admin/oauth/authorize?', $url);
        $this->assertStringContainsString('client_id=cid', $url);
        $this->assertStringContainsString('state=state-1', $url);
        $this->assertStringNotContainsString('scope=', $url);

        $install = $service->managedInstallUrl('foo.myshopify.com');
        $this->assertSame('https://admin.shopify.com/oauth/install?client_id=cid', $install);
    }
}
