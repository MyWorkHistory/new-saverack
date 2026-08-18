<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\Role;
use App\Models\User;
use App\Services\ShopifyConnectionService;
use App\Services\ShopifyOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShopifyOAuthApiTest extends TestCase
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

    private function configureOAuth(): void
    {
        config([
            'services.shopify.client_id' => 'test-client-id',
            'services.shopify.client_secret' => 'test-client-secret',
            'services.shopify.scopes' => 'read_orders,write_orders',
            'services.shopify.oauth_redirect_uri' => 'https://app.saverack.com/api/shopify/oauth/callback',
            'app.url' => 'https://app.saverack.com',
        ]);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function signQuery(array $params): array
    {
        unset($params['hmac'], $params['signature']);
        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key.'='.$value;
        }
        $params['hmac'] = hash_hmac('sha256', implode('&', $parts), 'test-client-secret');

        return $params;
    }

    public function test_oauth_start_returns_authorization_url(): void
    {
        $this->configureOAuth();
        $this->actingAsAdmin();
        $account = ClientAccount::query()->create([
            'company_name' => 'OAuth Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        $response = $this->postJson("/api/client-accounts/{$account->id}/shopify-connection/oauth/start", [
            'shop_domain' => 'test-store-wke6tzxl.myshopify.com',
        ])->assertOk();

        $url = (string) $response->json('authorization_url');
        $this->assertStringContainsString(
            'https://admin.shopify.com/oauth/install?',
            $url
        );
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringNotContainsString('/oauth/authorize?', $url);
        $this->assertStringNotContainsString('/store/', $url);
        $this->assertSame('test-store-wke6tzxl.myshopify.com', $response->json('shop_domain'));
    }

    public function test_oauth_start_requires_configuration(): void
    {
        config([
            'services.shopify.client_id' => '',
            'services.shopify.client_secret' => '',
        ]);
        $this->actingAsAdmin();
        $account = ClientAccount::query()->create([
            'company_name' => 'OAuth Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        $this->postJson("/api/client-accounts/{$account->id}/shopify-connection/oauth/start", [
            'shop_domain' => 'test-store-wke6tzxl.myshopify.com',
        ])->assertStatus(422);
    }

    public function test_oauth_callback_exchanges_code_and_connects(): void
    {
        $this->configureOAuth();
        $account = ClientAccount::query()->create([
            'company_name' => 'OAuth Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        /** @var ShopifyOAuthService $oauth */
        $oauth = app(ShopifyOAuthService::class);
        $state = $oauth->createState([
            'account_id' => (int) $account->id,
            'shop' => 'test-store-wke6tzxl.myshopify.com',
            'user_id' => 1,
            'import' => true,
        ]);

        Http::fake([
            'https://test-store-wke6tzxl.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_oauth_test_token',
                'scope' => 'read_orders,write_orders',
            ], 200),
        ]);

        $connection = new ClientAccountShopifyConnection([
            'client_account_id' => $account->id,
            'shop_domain' => 'test-store-wke6tzxl.myshopify.com',
            'status' => ClientAccountShopifyConnection::STATUS_IMPORTING,
        ]);
        $connection->id = 99;

        $mock = Mockery::mock(ShopifyConnectionService::class);
        $mock->shouldReceive('connectAndImport')
            ->once()
            ->withArgs(function (ClientAccount $acc, array $input) use ($account) {
                return (int) $acc->id === (int) $account->id
                    && $input['shop_domain'] === 'test-store-wke6tzxl.myshopify.com'
                    && $input['admin_api_access_token'] === 'shpat_oauth_test_token'
                    && $input['import'] === false;
            })
            ->andReturn($connection);
        $this->app->instance(ShopifyConnectionService::class, $mock);

        $query = $this->signQuery([
            'code' => 'auth-code-1',
            'shop' => 'test-store-wke6tzxl.myshopify.com',
            'state' => $state,
            'timestamp' => (string) time(),
        ]);

        $this->get('/api/shopify/oauth/callback?'.http_build_query($query))
            ->assertRedirect(
                'https://app.saverack.com/admin/clients/accounts/'.$account->id.'?tab=settings&shopify_oauth=success'
            );
    }

    public function test_oauth_callback_rejects_invalid_hmac(): void
    {
        $this->configureOAuth();
        $account = ClientAccount::query()->create([
            'company_name' => 'OAuth Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        /** @var ShopifyOAuthService $oauth */
        $oauth = app(ShopifyOAuthService::class);
        $state = $oauth->createState([
            'account_id' => (int) $account->id,
            'shop' => 'test-store-wke6tzxl.myshopify.com',
            'user_id' => 1,
        ]);

        $query = [
            'code' => 'auth-code-1',
            'shop' => 'test-store-wke6tzxl.myshopify.com',
            'state' => $state,
            'timestamp' => (string) time(),
            'hmac' => 'deadbeef',
        ];

        $response = $this->get('/api/shopify/oauth/callback?'.http_build_query($query));
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('shopify_oauth=error', $location);
        $this->assertStringContainsString('Invalid%20Shopify%20OAuth%20signature', $location);
    }

    public function test_oauth_install_redirects_to_authorize_with_default_account(): void
    {
        $this->configureOAuth();
        $account = ClientAccount::query()->create([
            'company_name' => 'OAuth Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
        config(['services.shopify.oauth_default_account_id' => (string) $account->id]);

        $response = $this->get('/api/shopify/oauth/install?shop=test-store-wke6tzxl.myshopify.com');
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString(
            'https://test-store-wke6tzxl.myshopify.com/admin/oauth/authorize?',
            $location
        );
        $this->assertStringContainsString('client_id=test-client-id', $location);
        $this->assertStringNotContainsString('scope=', $location);
    }

    public function test_oauth_callback_without_code_explains_usage(): void
    {
        $this->configureOAuth();

        $response = $this->get('/api/shopify/oauth/callback');
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('shopify_oauth=error', $location);
        $this->assertStringContainsString('Connect%20With%20Shopify', $location);
    }

    public function test_show_connection_includes_oauth_configured_flag(): void
    {
        $this->configureOAuth();
        $this->actingAsAdmin();
        $account = ClientAccount::query()->create([
            'company_name' => 'OAuth Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        $this->getJson("/api/client-accounts/{$account->id}/shopify-connection")
            ->assertOk()
            ->assertJsonPath('oauth_configured', true);
    }
}
