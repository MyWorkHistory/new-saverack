<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\Permission;
use App\Models\PricingFeeTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalFulfillmentPricingApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsPortalUser(ClientAccount $account): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'View inventory', 'module' => 'inventory']
        );
        $user = User::factory()->create([
            'client_account_id' => $account->id,
            'is_account_primary' => true,
            'status' => 'pending',
        ]);
        $user->permissions()->attach($permission->id);
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsStaff(): User
    {
        $user = User::factory()->create([
            'client_account_id' => null,
        ]);
        foreach (['clients.view', 'clients.update'] as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'module' => 'clients']
            );
            $user->permissions()->attach($permission->id);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_onboarding_includes_fulfillment_pricing_payload(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Pricing Portal Co',
            'email' => 'pricing-portal@example.test',
        ]);
        $this->actingAsPortalUser($account);

        $show = $this->getJson('/api/portal/onboarding');
        $show->assertOk();
        $show->assertJsonPath('fulfillment_pricing.status', 'not_completed');
        $show->assertJsonPath('fulfillment_pricing.pricing_status', 'pending');
        $show->assertJsonPath('fulfillment_pricing.approved', false);
        $show->assertJsonPath('tasks.2.id', 'fulfillment_pricing');
        $show->assertJsonPath('tasks.2.status', 'not_completed');
        $show->assertJsonPath('progress.total', 11);
    }

    public function test_accept_requires_approved_status(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Pricing Pending Co',
            'email' => 'pricing-pending@example.test',
        ]);
        $this->actingAsPortalUser($account);

        $this->postJson('/api/portal/onboarding/fulfillment-pricing/accept')
            ->assertStatus(422);
    }

    public function test_staff_can_approve_and_client_can_accept_and_download_pdf(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Pricing Approve Co',
            'email' => 'pricing-approve@example.test',
        ]);
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_FULFILLMENT,
            'line_code' => ClientAccountFee::LINE_FIRST_PICK,
            'label' => 'First Pick',
            'description' => 'Pick fee',
            'amount' => 1.25,
            'sort_order' => 1,
        ]);
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => PricingFeeTemplate::CATEGORY_POSTAGE,
            'line_code' => 'postage_usps',
            'label' => 'USPS Postage PortalHidden',
            'description' => 'Admin only',
            'amount' => 0.25,
            'sort_order' => 99,
        ]);

        $this->actingAsStaff();
        $approve = $this->patchJson(
            '/api/client-accounts/'.$account->id.'/fulfillment-pricing/status',
            ['status' => 'approved']
        );
        $approve->assertOk();
        $approve->assertJsonPath('fulfillment_pricing_status', 'approved');
        $this->assertNotNull($account->fresh()->fulfillment_pricing_approved_at);
        $this->assertNull($account->fresh()->fulfillment_pricing_accepted_at);
        $verifications = $account->fresh()->onboarding_verifications;
        $this->assertTrue(
            ! is_array($verifications)
            || empty($verifications['fulfillment_pricing']['verified_at'] ?? null)
        );

        $pdfAdmin = $this->get('/api/client-accounts/'.$account->id.'/onboarding/fulfillment-pricing.pdf');
        $pdfAdmin->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfAdmin->headers->get('content-type'));
        $this->assertStringNotContainsString('USPS Postage PortalHidden', $pdfAdmin->getContent());

        $this->actingAsPortalUser($account);
        $show = $this->getJson('/api/portal/onboarding');
        $show->assertOk();
        $show->assertJsonPath('fulfillment_pricing.approved', true);
        $show->assertJsonPath('fulfillment_pricing.status', 'not_completed');
        $show->assertJsonPath('tasks.2.status', 'not_completed');
        $this->assertNotEmpty($show->json('fulfillment_pricing.fees'));
        $portalCategories = [];
        foreach ($show->json('fulfillment_pricing.fees') as $feeRow) {
            $this->assertIsArray($feeRow);
            $this->assertArrayNotHasKey('cost', $feeRow);
            $this->assertArrayNotHasKey('default_cost', $feeRow);
            $this->assertArrayNotHasKey('cost_is_override', $feeRow);
            $portalCategories[] = (string) ($feeRow['category'] ?? '');
            $this->assertNotSame('USPS Postage PortalHidden', (string) ($feeRow['name'] ?? ''));
        }
        $this->assertContains(ClientAccountFee::GROUP_FULFILLMENT, $portalCategories);
        $this->assertNotContains(PricingFeeTemplate::CATEGORY_POSTAGE, $portalCategories);

        $accept = $this->postJson('/api/portal/onboarding/fulfillment-pricing/accept');
        $accept->assertOk();
        $accept->assertJsonPath('fulfillment_pricing.status', 'completed');
        $accept->assertJsonPath('tasks.2.status', 'completed');
        $this->assertNotNull($account->fresh()->fulfillment_pricing_accepted_at);

        $pdfPortal = $this->get('/api/portal/onboarding/fulfillment-pricing.pdf');
        $pdfPortal->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfPortal->headers->get('content-type'));
        $this->assertStringNotContainsString('USPS Postage PortalHidden', $pdfPortal->getContent());
    }

    public function test_staff_verify_does_not_complete_pricing(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Pricing Verify Split Co',
            'email' => 'pricing-verify-split@example.test',
            'fulfillment_pricing_status' => ClientAccount::FULFILLMENT_PRICING_STATUS_APPROVED,
            'fulfillment_pricing_approved_at' => now(),
        ]);

        $this->actingAsStaff();

        $verifyBeforeAccept = $this->patchJson(
            '/api/client-accounts/'.$account->id.'/onboarding/tasks/fulfillment_pricing/verification',
            ['verified' => true]
        );
        $verifyBeforeAccept->assertStatus(422);
        $this->assertNull($account->fresh()->fulfillment_pricing_accepted_at);

        $complete = $this->postJson(
            '/api/client-accounts/'.$account->id.'/onboarding/fulfillment-pricing/accept'
        );
        $complete->assertOk();
        $complete->assertJsonPath('fulfillment_pricing.status', 'completed');
        $tasksAfterComplete = collect($complete->json('tasks'));
        $pricingTask = $tasksAfterComplete->firstWhere('id', 'fulfillment_pricing');
        $this->assertIsArray($pricingTask);
        $this->assertSame('completed', $pricingTask['status'] ?? null);
        $this->assertFalse((bool) ($pricingTask['verified'] ?? true));
        $this->assertNotNull($account->fresh()->fulfillment_pricing_accepted_at);
        $verifications = $account->fresh()->onboarding_verifications;
        $this->assertTrue(
            ! is_array($verifications)
            || empty($verifications['fulfillment_pricing']['verified_at'] ?? null)
        );

        $verify = $this->patchJson(
            '/api/client-accounts/'.$account->id.'/onboarding/tasks/fulfillment_pricing/verification',
            ['verified' => true]
        );
        $verify->assertOk();
        $pricingTaskVerified = collect($verify->json('tasks'))->firstWhere('id', 'fulfillment_pricing');
        $this->assertIsArray($pricingTaskVerified);
        $this->assertSame('completed', $pricingTaskVerified['status'] ?? null);
        $this->assertTrue((bool) ($pricingTaskVerified['verified'] ?? false));
        $this->assertNotNull($account->fresh()->fulfillment_pricing_accepted_at);
    }

    public function test_pending_pdf_still_downloads(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Pricing Empty Co',
            'email' => 'pricing-empty@example.test',
        ]);
        $this->actingAsPortalUser($account);

        $pdf = $this->get('/api/portal/onboarding/fulfillment-pricing.pdf');
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
    }
}
