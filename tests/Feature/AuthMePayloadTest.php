<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthMePayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_includes_client_account_company_name_for_portal_user(): void
    {
        $account = ClientAccount::create([
            'company_name' => 'Acme Logistics LLC',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-me-test-1',
        ]);
        $user = User::factory()->create(['client_account_id' => $account->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('client_account_id', $account->id)
            ->assertJsonPath('client_account_company_name', 'Acme Logistics LLC');
    }

    public function test_me_returns_null_client_account_company_name_for_staff_user(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('client_account_id', null)
            ->assertJsonPath('client_account_company_name', null);
    }
}
