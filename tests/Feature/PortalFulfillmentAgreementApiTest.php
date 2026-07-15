<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\TermsOfService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalFulfillmentAgreementApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsPortalUser(ClientAccount $account): User
    {
        $user = User::factory()->create([
            'client_account_id' => $account->id,
            'is_account_primary' => true,
            'status' => 'pending',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_onboarding_includes_fulfillment_agreement_and_accept(): void
    {
        TermsOfService::query()->create([
            'body' => '<p>Fulfillment terms body</p>',
        ]);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Agreement Portal Co',
            'email' => 'agreement-portal@example.test',
        ]);
        $this->actingAsPortalUser($account);

        $show = $this->getJson('/api/portal/onboarding');
        $show->assertOk();
        $show->assertJsonPath('fulfillment_agreement.status', 'not_completed');
        $show->assertJsonPath('fulfillment_agreement.body', '<p>Fulfillment terms body</p>');
        $this->assertNull($show->json('fulfillment_agreement.accepted_at'));

        $accept = $this->postJson('/api/portal/onboarding/fulfillment-agreement/accept');
        $accept->assertOk();
        $accept->assertJsonPath('fulfillment_agreement.status', 'completed');
        $this->assertNotNull($accept->json('fulfillment_agreement.accepted_at'));

        $this->assertNotNull($account->fresh()->fulfillment_agreement_accepted_at);
    }
}
