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
        $this->assertArrayHasKey('uses_field_verification', $first);
        $this->assertArrayHasKey('verification_fields', $first);
        $this->assertArrayHasKey('verification_fields_complete', $first);
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

    public function test_admin_can_toggle_field_verification(): void
    {
        $this->staffWithClientsUpdate();

        $account = ClientAccount::create([
            'company_name' => 'Field Toggle Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'field-toggle@test.com',
        ]);
        User::factory()->create([
            'client_account_id' => $account->id,
            'is_account_primary' => true,
            'name' => 'Primary',
            'email' => 'primary@test.com',
        ]);

        $response = $this->patchJson(
            '/api/client-accounts/'.$account->id.'/onboarding/tasks/branding_information/verification/fields/brand_name',
            ['checked' => true]
        );

        $response->assertOk();
        $branding = collect($response->json('tasks'))->firstWhere('id', 'branding_information');
        $this->assertNotNull($branding);
        $this->assertTrue($branding['verification_fields']['brand_name']);
        $this->assertTrue($branding['verification_fields_complete']);
    }

    public function test_admin_cannot_verify_branding_until_field_checks_complete(): void
    {
        $this->staffWithClientsUpdate();

        $account = ClientAccount::create([
            'company_name' => 'Branding Verify Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'branding-verify@test.com',
        ]);
        User::factory()->create([
            'client_account_id' => $account->id,
            'is_account_primary' => true,
            'name' => 'Primary',
            'email' => 'primary@test.com',
        ]);

        $response = $this->patchJson(
            '/api/client-accounts/'.$account->id.'/onboarding/tasks/branding_information/verification',
            ['verified' => true]
        );

        $response->assertStatus(422);
    }

    public function test_admin_payment_method_link_marks_billing_started(): void
    {
        $this->staffWithClientsUpdate();

        $account = ClientAccount::create([
            'company_name' => 'Admin PM Link Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'admin-pm-link@test.com',
        ]);

        $response = $this->postJson(
            '/api/client-accounts/'.$account->id.'/onboarding/billing/payment-method-link',
            ['method' => 'ach']
        );

        $response->assertOk();
        $response->assertJsonStructure(['url', 'method', 'onboarding']);
        $this->assertStringContainsString('/payment-method/', (string) $response->json('url'));
        $response->assertJsonPath('onboarding.profile.onboarding_billing_method', 'ach');
        $response->assertJsonPath('onboarding.profile.onboarding_billing_status', 'processing');

        $this->assertDatabaseHas('payment_method_links', [
            'client_account_id' => $account->id,
            'method' => 'ach',
        ]);
    }
}
