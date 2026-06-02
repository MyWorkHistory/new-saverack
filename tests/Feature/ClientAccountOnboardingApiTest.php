<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\PortalOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientAccountOnboardingApiTest extends TestCase
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

    public function test_admin_can_load_onboarding_with_verification_fields(): void
    {
        $this->staffWithClientsUpdate();

        $account = ClientAccount::create([
            'company_name' => 'Admin Onboard Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'co@test.com',
            'onboarding_billing_status' => PortalOnboardingService::BILLING_STATUS_COMPLETED,
            'onboarding_billing_method' => PortalOnboardingService::BILLING_METHOD_MANUAL,
            'default_payment_type' => 'Manual',
        ]);
        User::factory()->create([
            'client_account_id' => $account->id,
            'is_account_primary' => true,
            'name' => 'Primary',
            'email' => 'primary@test.com',
        ]);

        $response = $this->getJson('/api/client-accounts/'.$account->id.'/onboarding');

        $response->assertOk();
        $response->assertJsonStructure(['tasks', 'profile', 'preferences']);
        $first = $response->json('tasks.0');
        $this->assertArrayHasKey('verified', $first);
        $this->assertArrayHasKey('verification_status', $first);
    }

    public function test_admin_can_verify_onboarding_task(): void
    {
        $this->staffWithClientsUpdate();

        $account = ClientAccount::create([
            'company_name' => 'Verify Task Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'verify@test.com',
        ]);
        User::factory()->create([
            'client_account_id' => $account->id,
            'is_account_primary' => true,
            'name' => 'Primary',
            'email' => 'primary@test.com',
        ]);

        $response = $this->patchJson(
            '/api/client-accounts/'.$account->id.'/onboarding/tasks/billing_information/verification',
            ['verified' => true]
        );

        $response->assertOk();
        $billing = collect($response->json('tasks'))->firstWhere('id', 'billing_information');
        $this->assertNotNull($billing);
        $this->assertTrue($billing['verified']);
    }
}
