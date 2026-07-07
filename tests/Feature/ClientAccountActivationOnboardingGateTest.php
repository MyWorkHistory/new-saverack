<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\PortalOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientAccountActivationOnboardingGateTest extends TestCase
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

    public function test_cannot_activate_account_until_onboarding_is_complete_and_verified(): void
    {
        $this->staffWithClientsUpdate();

        $account = ClientAccount::create([
            'company_name' => 'Incomplete Onboarding Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'incomplete@test.com',
            'shiphero_customer_account_id' => 'sh-123',
        ]);

        $response = $this->patchJson('/api/client-accounts/'.$account->id, [
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
        $this->assertStringContainsString(
            'Please complete onboarding to active account.',
            (string) collect($response->json('errors.status'))->first()
        );
        $this->assertSame(ClientAccount::STATUS_PENDING, $account->fresh()->status);
    }

    public function test_can_pause_active_account_without_onboarding_gate(): void
    {
        $this->staffWithClientsUpdate();

        $account = ClientAccount::create([
            'company_name' => 'Legacy Active Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'legacy@test.com',
            'shiphero_customer_account_id' => 'sh-456',
        ]);

        $response = $this->patchJson('/api/client-accounts/'.$account->id, [
            'status' => ClientAccount::STATUS_PAUSED,
            'pause_reason' => ClientAccount::PAUSE_REASON_ADMIN,
        ]);

        $response->assertOk();
        $this->assertSame(ClientAccount::STATUS_PAUSED, $account->fresh()->status);
    }
}
