<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ClientBrandLogoService;
use App\Services\PortalOnboardingService;
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
}
