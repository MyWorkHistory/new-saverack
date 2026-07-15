<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\PaymentMethodLink;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountPaymentMethodService;
use App\Services\PortalOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountPaymentMethodApiTest extends TestCase
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

    private function staffWithClients(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        foreach (['clients.view', 'clients.update'] as $key) {
            $perm = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'module' => 'clients']
            );
            $user->permissions()->attach($perm->id);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_staff_can_create_payment_method_link(): void
    {
        $this->staffWithClients();
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'PM Link Co',
            'email' => 'pm-link@example.test',
        ]);

        $res = $this->postJson('/api/client-accounts/'.$account->id.'/payment-method-links', [
            'method' => 'credit_card',
        ]);
        $res->assertCreated();
        $res->assertJsonStructure(['url', 'token', 'method']);
        $this->assertStringContainsString('/payment-method/', (string) $res->json('url'));
        $this->assertDatabaseHas('payment_method_links', [
            'client_account_id' => $account->id,
            'method' => 'credit_card',
        ]);
    }

    public function test_public_thanks_page_renders_for_known_token(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Thanks Co',
            'email' => 'thanks@example.test',
        ]);
        $link = PaymentMethodLink::query()->create([
            'client_account_id' => $account->id,
            'token' => 'tokenthanks1234567890abcdefghij',
            'method' => PaymentMethodLink::METHOD_CREDIT_CARD,
            'expires_at' => now()->addDay(),
            'consumed_at' => now(),
        ]);

        $this->get('/payment-method/'.$link->token.'/thanks')
            ->assertOk()
            ->assertSee('Thank You', false);
    }

    public function test_pin_unlock_rejects_wrong_pin_without_stripe_call(): void
    {
        $this->staffWithClients();
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'PIN Co',
            'email' => 'pin@example.test',
            'stripe_customer_id' => 'cus_test_pin',
        ]);

        $this->postJson(
            '/api/client-accounts/'.$account->id.'/stripe-payment-methods/pm_test/unlock',
            ['pin' => '0000']
        )->assertForbidden()
            ->assertJsonPath('message', 'Incorrect PIN.');
    }

    public function test_pin_service_matches_configured_pin(): void
    {
        config(['crm.payment_method_view_pin' => '0912']);
        $service = app(AccountPaymentMethodService::class);
        $this->assertTrue($service->pinMatches('0912'));
        $this->assertFalse($service->pinMatches('0913'));
    }

    public function test_expired_public_setup_intent_returns_404(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Expired Link Co',
            'email' => 'expired-link@example.test',
        ]);
        $link = PaymentMethodLink::query()->create([
            'client_account_id' => $account->id,
            'token' => 'expiredtoken1234567890abcdefghi',
            'method' => PaymentMethodLink::METHOD_ACH,
            'expires_at' => now()->subHour(),
        ]);

        $this->postJson('/api/public/payment-method/'.$link->token.'/setup-intent')
            ->assertNotFound();
    }

    public function test_admin_can_create_link_too(): void
    {
        $this->actingAsAdmin();
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Admin PM Co',
            'email' => 'admin-pm@example.test',
        ]);

        $this->postJson('/api/client-accounts/'.$account->id.'/payment-method-links', [
            'method' => 'ach',
        ])->assertCreated()->assertJsonPath('method', 'ach');
    }

    public function test_completing_payment_method_link_updates_onboarding_billing(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Onboard PM Complete Co',
            'email' => 'onboard-pm@example.test',
            'onboarding_billing_method' => PortalOnboardingService::BILLING_METHOD_CREDIT_CARD,
            'onboarding_billing_status' => PortalOnboardingService::BILLING_STATUS_NOT_STARTED,
            'stripe_customer_id' => 'cus_test_onboard_pm',
        ]);
        $link = PaymentMethodLink::query()->create([
            'client_account_id' => $account->id,
            'token' => 'completetoken1234567890abcdefghi',
            'method' => PaymentMethodLink::METHOD_CREDIT_CARD,
            'expires_at' => now()->addDay(),
        ]);

        $this->postJson('/api/public/payment-method/'.$link->token.'/complete', [
            'payment_method_id' => 'pm_test_complete',
        ])->assertOk()->assertJsonPath('ok', true);

        $account->refresh();
        $this->assertSame(PortalOnboardingService::BILLING_STATUS_COMPLETED, $account->onboarding_billing_status);
        $this->assertSame(PortalOnboardingService::BILLING_METHOD_CREDIT_CARD, $account->onboarding_billing_method);
        $this->assertSame('Credit Card', $account->default_payment_type);
        $this->assertNotNull($link->fresh()->consumed_at);
    }

    public function test_completing_ach_payment_method_link_sets_ach_defaults(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Onboard ACH Complete Co',
            'email' => 'onboard-ach@example.test',
            'onboarding_billing_status' => PortalOnboardingService::BILLING_STATUS_PROCESSING,
            'onboarding_billing_method' => PortalOnboardingService::BILLING_METHOD_ACH,
        ]);
        $link = PaymentMethodLink::query()->create([
            'client_account_id' => $account->id,
            'token' => 'completeachtoken1234567890abcdef',
            'method' => PaymentMethodLink::METHOD_ACH,
            'expires_at' => now()->addDay(),
        ]);

        $this->postJson('/api/public/payment-method/'.$link->token.'/complete')
            ->assertOk();

        $account->refresh();
        $this->assertSame(PortalOnboardingService::BILLING_STATUS_COMPLETED, $account->onboarding_billing_status);
        $this->assertSame(PortalOnboardingService::BILLING_METHOD_ACH, $account->onboarding_billing_method);
        $this->assertSame('ACH', $account->default_payment_type);
    }
}
