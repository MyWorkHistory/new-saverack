<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\ShipHeroClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ClientAccountShipHeroStoresTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function permission(string $key, string $label): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => $key],
            ['label' => $label, 'module' => 'stores']
        );
    }

    /**
     * @return array{user: User, account: ClientAccount}
     */
    private function staffWithStoresPerms(): array
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->permission('clients.view', 'View clients')->id,
            $this->permission('stores.view', 'View stores')->id,
            $this->permission('stores.create', 'Create stores')->id,
        ]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'company_name' => 'ShipHero Stores Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-stores-99',
            'email' => 'stores-co@example.test',
        ]);

        return ['user' => $user, 'account' => $account];
    }

    /**
     * @return array<string, mixed>
     */
    private function shipheroStoresGraphqlResponse(): array
    {
        return [
            'data' => [
                'user' => [
                    'request_id' => 'req-stores-1',
                    'data' => [
                        'stores' => [
                            [
                                'id' => 'U3RvcmU6MTIz',
                                'legacy_id' => '32363',
                                'shop_name' => 'example.myshopify.com',
                            ],
                            [
                                'id' => 'U3RvcmU6NDU2',
                                'legacy_id' => '45678',
                                'shop_name' => 'Second Shop',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function mockShipHeroClient(): void
    {
        $client = Mockery::mock(ShipHeroClient::class);
        $client->shouldReceive('query')
            ->andReturn($this->shipheroStoresGraphqlResponse());
        $this->app->instance(ShipHeroClient::class, $client);
    }

    public function test_import_returns_stores_with_settings_urls(): void
    {
        ['account' => $account] = $this->staffWithStoresPerms();
        $this->mockShipHeroClient();

        $response = $this->postJson('/api/client-accounts/'.$account->id.'/shiphero-stores/import');

        $response->assertOk()
            ->assertJsonCount(2, 'stores')
            ->assertJsonPath('stores.0.shop_name', 'example.myshopify.com')
            ->assertJsonPath('stores.0.legacy_id', '32363')
            ->assertJsonPath(
                'stores.0.settings_url',
                'https://app.shiphero.com/dashboard/stores/settings?shop=32363'
            )
            ->assertJsonPath('shiphero_customer_account_id', 'sh-stores-99');
    }

    public function test_index_returns_cached_stores_after_import(): void
    {
        ['account' => $account] = $this->staffWithStoresPerms();
        $this->mockShipHeroClient();

        $this->postJson('/api/client-accounts/'.$account->id.'/shiphero-stores/import')->assertOk();

        $index = $this->getJson('/api/client-accounts/'.$account->id.'/shiphero-stores');
        $index->assertOk()
            ->assertJsonCount(2, 'stores')
            ->assertJsonPath('can_import', true);
        $this->assertNotNull($index->json('imported_at'));
    }

    public function test_import_requires_shiphero_customer_account_id(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->permission('clients.view', 'View clients')->id,
            $this->permission('stores.view', 'View stores')->id,
            $this->permission('stores.create', 'Create stores')->id,
        ]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'company_name' => 'No ShipHero ID',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => null,
        ]);

        $this->postJson('/api/client-accounts/'.$account->id.'/shiphero-stores/import')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This account has no ShipHero customer account ID.');
    }

    public function test_import_requires_stores_create_permission(): void
    {
        $user = User::factory()->create();
        $user->permissions()->sync([
            $this->permission('clients.view', 'View clients')->id,
            $this->permission('stores.view', 'View stores')->id,
        ]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'company_name' => 'View Only Stores',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-view-only',
        ]);

        $this->postJson('/api/client-accounts/'.$account->id.'/shiphero-stores/import')
            ->assertForbidden();
    }

    public function test_portal_user_cannot_import_stores(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Portal Stores Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-portal-stores',
        ]);

        $portalUser = User::factory()->create([
            'client_account_id' => $account->id,
        ]);
        $portalUser->permissions()->sync([
            $this->permission('stores.view', 'View stores')->id,
            $this->permission('stores.create', 'Create stores')->id,
        ]);
        Sanctum::actingAs($portalUser);

        $this->postJson('/api/client-accounts/'.$account->id.'/shiphero-stores/import')
            ->assertForbidden();
    }

    public function test_index_returns_empty_when_not_imported(): void
    {
        ['account' => $account] = $this->staffWithStoresPerms();
        Cache::flush();

        $this->getJson('/api/client-accounts/'.$account->id.'/shiphero-stores')
            ->assertOk()
            ->assertJsonPath('stores', [])
            ->assertJsonPath('imported_at', null);
    }
}
