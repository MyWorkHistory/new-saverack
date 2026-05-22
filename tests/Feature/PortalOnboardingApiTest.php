<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\PortalOnboardingStripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Stripe\Event;
use Tests\TestCase;

class PortalOnboardingApiTest extends TestCase
{
    use RefreshDatabase;

    private function inventoryViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'View inventory', 'module' => 'inventory']
        );
    }

    /**
     * @return array{0: ClientAccount, 1: User}
     */
    private function pendingPortalUser(): array
    {
        $account = ClientAccount::create([
            'company_name' => 'Onboard Test Co',
            'status' => ClientAccount::STATUS_PENDING,
            'email' => 'billing@onboard.test',
        ]);
        $user = User::factory()->create([
            'client_account_id' => $account->id,
            'email' => 'portal@onboard.test',
            'name' => 'Portal User',
        ]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        return [$account, $user];
    }

    public function test_onboarding_returns_two_tasks_with_account_incomplete(): void
    {
        [$account] = $this->pendingPortalUser();

        $response = $this->getJson('/api/portal/onboarding');

        $response->assertOk();
        $response->assertJsonPath('client_account_status', ClientAccount::STATUS_PENDING);
        $response->assertJsonCount(2, 'tasks');
        $response->assertJsonPath('tasks.0.id', 'account_information');
        $response->assertJsonPath('tasks.0.status', 'not_completed');
        $response->assertJsonPath('profile.client_account_id', $account->id);
        $response->assertJsonPath('profile.account_information_complete', false);
        $response->assertJsonPath('progress.total', 2);
        $response->assertJsonPath('progress.completed', 0);
    }

    public function test_profile_patch_completes_account_task_when_all_fields_present(): void
    {
        [, $user] = $this->pendingPortalUser();

        $response = $this->patchJson('/api/portal/profile', [
            'name' => 'Jane Doe',
            'email' => $user->email,
            'company_name' => 'Onboard Test Co',
            'phone' => '555-0100',
            'street' => '3135 Drane Field Rd',
            'city' => 'Lakeland',
            'state' => 'FL',
            'zip' => '33811',
            'country' => 'US',
        ]);

        $response->assertOk();
        $response->assertJsonPath('account_information_complete', true);

        $onboarding = $this->getJson('/api/portal/onboarding');
        $onboarding->assertOk();
        $onboarding->assertJsonPath('tasks.0.status', 'completed');
        $onboarding->assertJsonPath('progress.completed', 1);
    }

    public function test_manual_billing_marks_billing_task_complete(): void
    {
        $this->pendingPortalUser();

        $response = $this->postJson('/api/portal/onboarding/billing/manual');

        $response->assertOk();
        $response->assertJsonPath('tasks.1.id', 'billing_information');
        $response->assertJsonPath('tasks.1.status', 'completed');
        $response->assertJsonPath('profile.onboarding_billing_method', 'manual');
        $response->assertJsonPath('profile.onboarding_billing_status', 'completed');
    }

    public function test_onboarding_webhook_completes_billing_for_card_deposit(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Stripe Onboard Co',
            'status' => ClientAccount::STATUS_PENDING,
            'onboarding_billing_method' => 'credit_card',
            'onboarding_billing_status' => 'not_started',
        ]);

        $payload = [
            'id' => 'evt_test_onboard',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_onboard',
                    'customer' => 'cus_test_onboard',
                    'payment_method' => 'pm_test_onboard',
                    'metadata' => [
                        'purpose' => PortalOnboardingStripeService::METADATA_PURPOSE,
                        'client_account_id' => (string) $account->id,
                        'billing_method' => 'credit_card',
                    ],
                ],
            ],
        ];

        $event = Event::constructFrom($payload);
        $result = app(PortalOnboardingStripeService::class)->tryHandleEvent($event);

        $this->assertNotNull($result);
        $this->assertTrue($result['applied']);

        $account->refresh();
        $this->assertSame('credit_card', $account->onboarding_billing_method);
        $this->assertSame('completed', $account->onboarding_billing_status);
        $this->assertSame('Credit Card', $account->default_payment_type);
        $this->assertSame('cus_test_onboard', $account->stripe_customer_id);
    }
}
