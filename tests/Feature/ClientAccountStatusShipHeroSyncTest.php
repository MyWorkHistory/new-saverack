<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\ShipHeroCustomerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ClientAccountStatusShipHeroSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function staffWithClientsUpdate(): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'clients.update'],
            ['label' => 'Update client accounts', 'module' => 'clients']
        );
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach($permission->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_status_patch_invokes_shiphero_sync_when_customer_id_present(): void
    {
        $this->staffWithClientsUpdate();

        $account = ClientAccount::create([
            'company_name' => 'Sync Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'sync-co@test.com',
            'shiphero_customer_account_id' => '92441',
        ]);

        $mock = Mockery::mock(ShipHeroCustomerAccountService::class);
        $mock->shouldReceive('shouldHideOrdersFromApp')->andReturn(true);
        $mock->shouldReceive('syncHideOrdersFromApp')
            ->once()
            ->with(Mockery::on(function (ClientAccount $a) use ($account) {
                return (int) $a->id === (int) $account->id
                    && $a->status === ClientAccount::STATUS_PAUSED;
            }))
            ->andReturn(['ok' => true, 'message' => null]);
        $this->instance(ShipHeroCustomerAccountService::class, $mock);

        $response = $this->patchJson('/api/client-accounts/'.$account->id, [
            'status' => ClientAccount::STATUS_PAUSED,
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', ClientAccount::STATUS_PAUSED);
        $response->assertJsonPath('shiphero_sync.ok', true);
        $this->assertSame(ClientAccount::STATUS_PAUSED, $account->fresh()->status);
    }

    public function test_status_patch_returns_shiphero_sync_when_sync_skipped(): void
    {
        $this->staffWithClientsUpdate();

        $account = ClientAccount::create([
            'company_name' => 'No ShipHero Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'no-sh@test.com',
            'shiphero_customer_account_id' => null,
        ]);

        $response = $this->patchJson('/api/client-accounts/'.$account->id, [
            'status' => ClientAccount::STATUS_INACTIVE,
        ]);

        $response->assertOk();
        $response->assertJsonPath('shiphero_sync.ok', true);
        $this->assertStringContainsString(
            'skipped',
            (string) $response->json('shiphero_sync.message')
        );
    }
}
