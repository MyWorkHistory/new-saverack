<?php

namespace Tests\Feature;

use App\Jobs\RunShopifyOrderResyncJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\Role;
use App\Models\ShopifyOrder;
use App\Models\User;
use App\Services\ShopifyOrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShopifyOrderResyncApiTest extends TestCase
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

    /**
     * @return array{0: ClientAccount, 1: ClientAccountShopifyConnection}
     */
    private function connectedAccount(): array
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Shopify Resync Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
        $connection = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => $account->id,
            'shop_domain' => 'test.myshopify.com',
            'admin_api_access_token' => 'shpat_test',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);

        return [$account, $connection];
    }

    public function test_queues_unfulfilled_order_resync(): void
    {
        Bus::fake();
        $this->actingAsAdmin();
        [$account] = $this->connectedAccount();

        $this->postJson("/api/client-accounts/{$account->id}/shopify-connection/sync-orders", [
            'mode' => 'unfulfilled',
        ])
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('mode', 'unfulfilled');

        Bus::assertDispatched(RunShopifyOrderResyncJob::class, function (RunShopifyOrderResyncJob $job) {
            return $job->mode === RunShopifyOrderResyncJob::MODE_UNFULFILLED
                && $job->afterDate === null;
        });
    }

    public function test_queues_after_date_order_resync(): void
    {
        Bus::fake();
        $this->actingAsAdmin();
        [$account] = $this->connectedAccount();

        $this->postJson("/api/client-accounts/{$account->id}/shopify-connection/sync-orders", [
            'mode' => 'after_date',
            'after_date' => '2026-01-15',
        ])
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('mode', 'after_date');

        Bus::assertDispatched(RunShopifyOrderResyncJob::class, function (RunShopifyOrderResyncJob $job) {
            return $job->mode === RunShopifyOrderResyncJob::MODE_AFTER_DATE
                && $job->afterDate === '2026-01-15';
        });
    }

    public function test_after_date_requires_date(): void
    {
        Bus::fake();
        $this->actingAsAdmin();
        [$account] = $this->connectedAccount();

        $this->postJson("/api/client-accounts/{$account->id}/shopify-connection/sync-orders", [
            'mode' => 'after_date',
        ])->assertStatus(422);

        Bus::assertNothingDispatched();
    }

    public function test_syncs_specific_order_number_inline(): void
    {
        $this->actingAsAdmin();
        [$account, $connection] = $this->connectedAccount();

        $order = ShopifyOrder::query()->create([
            'connection_id' => $connection->id,
            'shopify_order_id' => '555',
            'name' => '#1234',
        ]);

        $mock = Mockery::mock(ShopifyOrderSyncService::class);
        $mock->shouldReceive('syncOrderByName')
            ->once()
            ->withArgs(function ($conn, $name) use ($connection) {
                return (int) $conn->id === (int) $connection->id
                    && $name === '1234';
            })
            ->andReturn($order);
        $this->app->instance(ShopifyOrderSyncService::class, $mock);

        $this->postJson("/api/client-accounts/{$account->id}/shopify-connection/sync-orders", [
            'mode' => 'order_number',
            'order_number' => '1234',
        ])
            ->assertOk()
            ->assertJsonPath('queued', false)
            ->assertJsonPath('synced', 1)
            ->assertJsonPath('order.name', '#1234');
    }

    public function test_order_number_requires_value(): void
    {
        $this->actingAsAdmin();
        [$account] = $this->connectedAccount();

        $this->postJson("/api/client-accounts/{$account->id}/shopify-connection/sync-orders", [
            'mode' => 'order_number',
            'order_number' => '  ',
        ])->assertStatus(422);
    }

    public function test_requires_connected_credentials(): void
    {
        Bus::fake();
        $this->actingAsAdmin();
        $account = ClientAccount::query()->create([
            'company_name' => 'No Shopify',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        $this->postJson("/api/client-accounts/{$account->id}/shopify-connection/sync-orders", [
            'mode' => 'unfulfilled',
        ])->assertStatus(422)->assertJsonPath('message', 'Connect Shopify credentials first.');

        Bus::assertNothingDispatched();
    }
}
