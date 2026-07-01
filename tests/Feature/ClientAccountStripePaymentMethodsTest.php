<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientAccountStripePaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private function clientsViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'clients.view'],
            ['label' => 'View client accounts', 'module' => 'clients']
        );
    }

    public function test_staff_can_load_empty_payment_methods_without_stripe_customer(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach($this->clientsViewPermission()->id);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'No Stripe Co',
            'email' => 'no-stripe@test.com',
        ]);

        $this->getJson('/api/client-accounts/'.$account->id.'/stripe-payment-methods')
            ->assertOk()
            ->assertJsonPath('methods', []);
    }

    public function test_guest_cannot_load_payment_methods(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Guest Stripe Co',
            'email' => 'guest-stripe@test.com',
        ]);

        $this->getJson('/api/client-accounts/'.$account->id.'/stripe-payment-methods')
            ->assertUnauthorized();
    }
}
