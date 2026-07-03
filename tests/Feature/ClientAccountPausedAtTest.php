<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientAccountPausedAtTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_patch_to_paused_sets_paused_at(): void
    {
        $this->staffWithClientsUpdate();

        $account = ClientAccount::create([
            'company_name' => 'Pause Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'pause-co@test.com',
        ]);

        $this->assertNull($account->paused_at);

        $response = $this->patchJson('/api/client-accounts/'.$account->id, [
            'status' => ClientAccount::STATUS_PAUSED,
        ]);

        $response->assertOk();
        $account->refresh();
        $this->assertSame(ClientAccount::STATUS_PAUSED, $account->status);
        $this->assertNotNull($account->paused_at);
    }

    public function test_patch_from_paused_to_active_clears_paused_at(): void
    {
        $this->staffWithClientsUpdate();

        $account = ClientAccount::create([
            'company_name' => 'Resume Co',
            'status' => ClientAccount::STATUS_PAUSED,
            'email' => 'resume-co@test.com',
            'paused_at' => now()->subDay(),
        ]);

        $response = $this->patchJson('/api/client-accounts/'.$account->id, [
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        $response->assertOk();
        $account->refresh();
        $this->assertSame(ClientAccount::STATUS_ACTIVE, $account->status);
        $this->assertNull($account->paused_at);
    }
}
