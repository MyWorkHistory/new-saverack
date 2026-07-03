<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ClientBrandLogoService;
use App\Services\PortalOnboardingService;
use App\Support\PortalOnboardingSectionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PortalOnboardingVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_set_task_verified_persists_per_item(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Verify Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'verify@test.com',
        ]);
        $service = new PortalOnboardingService(Mockery::mock(ClientBrandLogoService::class));

        $service->setTaskVerified($account, 'account_information', true, 99);
        $account->refresh();

        $this->assertTrue($service->isTaskVerified($account, 'account_information'));
        $verifications = $account->onboarding_verifications;
        $this->assertIsArray($verifications);
        $this->assertArrayHasKey('account_information', $verifications);
    }

    public function test_admin_payload_includes_verification_status_on_tasks(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Acme Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'admin@acme.test',
            'phone' => '5551234567',
            'street' => '1 Main',
            'city' => 'Town',
            'state' => 'FL',
            'zip' => '33801',
            'country' => 'US',
            'onboarding_billing_status' => PortalOnboardingService::BILLING_STATUS_COMPLETED,
            'onboarding_billing_method' => PortalOnboardingService::BILLING_METHOD_MANUAL,
            'default_payment_type' => 'Manual',
        ]);
        User::factory()->create([
            'client_account_id' => $account->id,
            'is_account_primary' => true,
            'name' => 'Jane Doe',
            'email' => 'jane@acme.test',
        ]);

        $service = new PortalOnboardingService(Mockery::mock(ClientBrandLogoService::class));
        $service->setTaskVerified($account, 'billing_information', true, 1);
        $account->refresh();

        $payload = $service->buildAdminOnboardingPayload($account);
        $billing = collect($payload['tasks'])->firstWhere('id', 'billing_information');

        $this->assertNotNull($billing);
        $this->assertTrue($billing['verified']);
        $this->assertSame('verified', $billing['verification_status']);
    }

    public function test_apply_admin_billing_method_sets_default_payment_type(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Billing Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'bill@test.com',
        ]);
        $service = new PortalOnboardingService(Mockery::mock(ClientBrandLogoService::class));

        $service->applyAdminBillingMethod($account, PortalOnboardingService::BILLING_METHOD_CREDIT_CARD);
        $account->refresh();

        $this->assertSame('Credit Card', $account->default_payment_type);
        $this->assertSame(PortalOnboardingService::BILLING_STATUS_COMPLETED, $account->onboarding_billing_status);

        $service->applyAdminBillingMethod($account->fresh(), PortalOnboardingService::BILLING_METHOD_ACH);
        $account->refresh();
        $this->assertSame('ACH', $account->default_payment_type);
    }

    public function test_task_titles_use_account_and_billing_information_labels(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Label Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'label@test.com',
        ]);
        $user = User::factory()->create([
            'client_account_id' => $account->id,
            'name' => 'Label User',
            'email' => 'user@label.test',
        ]);

        $service = new PortalOnboardingService(Mockery::mock(ClientBrandLogoService::class));
        $payload = $service->buildOnboardingPayload($user, $account);
        $titles = collect($payload['tasks'])->pluck('title')->all();

        $this->assertContains('Account Information', $titles);
        $this->assertContains('Billing Information', $titles);
        $this->assertNotContains('Add Account Information', $titles);
        $this->assertNotContains('Add Billing Information', $titles);
    }

    public function test_is_onboarding_ready_for_activation_requires_completed_and_verified_tasks(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Activation Gate Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'gate@test.com',
            'phone' => '5551234567',
            'street' => '1 Main',
            'city' => 'Town',
            'state' => 'FL',
            'zip' => '33801',
            'country' => 'US',
            'onboarding_billing_status' => PortalOnboardingService::BILLING_STATUS_COMPLETED,
            'onboarding_billing_method' => PortalOnboardingService::BILLING_METHOD_MANUAL,
            'default_payment_type' => 'Manual',
        ]);
        User::factory()->create([
            'client_account_id' => $account->id,
            'is_account_primary' => true,
            'name' => 'Jane Doe',
            'email' => 'jane@gate.test',
        ]);

        $service = new PortalOnboardingService(Mockery::mock(ClientBrandLogoService::class));
        $this->assertFalse($service->isOnboardingReadyForActivation($account->fresh()));

        foreach (PortalOnboardingService::ONBOARDING_TASK_IDS as $taskId) {
            $fresh = $account->fresh();
            if (PortalOnboardingSectionRegistry::taskUsesAdminFieldVerification($taskId)) {
                $prefs = is_array($fresh->onboarding_preferences) ? $fresh->onboarding_preferences : [];
                foreach (PortalOnboardingSectionRegistry::adminVerificationFieldKeysForPreferences($taskId, $prefs) as $fieldKey) {
                    $service->setTaskFieldVerified($fresh, $taskId, $fieldKey, true, 1);
                    $fresh = $account->fresh();
                }
            }
            $service->setTaskVerified($fresh, $taskId, true, 1);
        }
        $account->refresh();

        $this->assertFalse($service->isOnboardingReadyForActivation($account));
    }

    public function test_set_task_field_verified_persists_for_branding_brand_name_only(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Field Verify Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'field@test.com',
        ]);
        $service = new PortalOnboardingService(Mockery::mock(ClientBrandLogoService::class));

        $service->setTaskFieldVerified($account, 'branding_information', 'brand_name', true, 42);
        $account->refresh();

        $this->assertTrue($service->getTaskFieldVerifications($account, 'branding_information')['brand_name'] ?? false);
        $this->assertTrue($service->areTaskFieldVerificationsComplete($account, 'branding_information'));
    }

    public function test_cannot_verify_branding_until_brand_name_field_checked(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Branding Gate Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'brand-gate@test.com',
        ]);
        $service = new PortalOnboardingService(Mockery::mock(ClientBrandLogoService::class));

        $this->expectException(\InvalidArgumentException::class);
        $service->setTaskVerified($account, 'branding_information', true, 1);
    }

    public function test_unchecking_field_clears_section_verification(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Uncheck Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'uncheck@test.com',
        ]);
        $service = new PortalOnboardingService(Mockery::mock(ClientBrandLogoService::class));

        $service->setTaskFieldVerified($account, 'branding_information', 'brand_name', true, 1);
        $service->setTaskVerified($account->fresh(), 'branding_information', true, 1);
        $this->assertTrue($service->isTaskVerified($account->fresh(), 'branding_information'));

        $service->setTaskFieldVerified($account->fresh(), 'branding_information', 'brand_name', false, 1);
        $account->refresh();

        $this->assertFalse($service->isTaskVerified($account, 'branding_information'));
    }

    public function test_packing_slip_note_not_required_for_field_verification_when_include_note_no(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Packing Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'packing@test.com',
            'onboarding_preferences' => [
                'packing_slips_preferences' => [
                    'include_note' => 'no',
                ],
            ],
        ]);
        $service = new PortalOnboardingService(Mockery::mock(ClientBrandLogoService::class));

        $required = PortalOnboardingSectionRegistry::adminVerificationFieldKeysForPreferences(
            'packing_slips_preferences',
            $account->onboarding_preferences
        );

        $this->assertNotContains('packing_slip_note', $required);
    }

    public function test_admin_payload_includes_field_verification_metadata(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Meta Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'meta@test.com',
        ]);
        User::factory()->create([
            'client_account_id' => $account->id,
            'is_account_primary' => true,
            'name' => 'Meta User',
            'email' => 'meta-user@test.com',
        ]);

        $service = new PortalOnboardingService(Mockery::mock(ClientBrandLogoService::class));
        $service->setTaskFieldVerified($account, 'branding_information', 'brand_name', true, 1);
        $account->refresh();

        $payload = $service->buildAdminOnboardingPayload($account);
        $branding = collect($payload['tasks'])->firstWhere('id', 'branding_information');

        $this->assertNotNull($branding);
        $this->assertTrue($branding['uses_field_verification']);
        $this->assertTrue($branding['verification_fields']['brand_name']);
        $this->assertTrue($branding['verification_fields_complete']);
    }

    public function test_save_communication_email_syncs_notification_email_channel(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Comm Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'account@comm.test',
        ]);
        $service = new PortalOnboardingService(Mockery::mock(ClientBrandLogoService::class));

        $service->savePreferenceSection($account, 'communication_preferences', [
            'communication_method' => 'email',
            'contact_email' => 'notify@comm.test',
        ]);
        $account->refresh();

        $this->assertSame('notify@comm.test', $account->notification_email);
        $this->assertTrue($account->notify_email);
        $this->assertSame(
            'notify@comm.test',
            $account->onboarding_preferences['communication_preferences']['contact_email'] ?? null
        );
    }
}
