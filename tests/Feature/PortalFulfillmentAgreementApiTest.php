<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\TermsOfService;
use App\Models\User;
use App\Services\SlackDeliveryService;
use App\Support\FulfillmentAgreementPreamble;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class PortalFulfillmentAgreementApiTest extends TestCase
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

    private function seedTerms(): void
    {
        TermsOfService::query()->create([
            'body' => '<p>Fulfillment terms body</p>',
        ]);
    }

    public function test_onboarding_includes_fulfillment_agreement_payload(): void
    {
        $this->seedTerms();

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Agreement Portal Co',
            'email' => 'agreement-portal@example.test',
        ]);
        $this->actingAsPortalUser($account);

        $show = $this->getJson('/api/portal/onboarding');
        $show->assertOk();
        $show->assertJsonPath('fulfillment_agreement.status', 'not_completed');
        $body = (string) $show->json('fulfillment_agreement.body');
        $this->assertStringContainsString('This Fulfillment Services Agreement', $body);
        $this->assertStringContainsString('Agreement Portal Co', $body);
        $this->assertStringContainsString('<p>Fulfillment terms body</p>', $body);
        $show->assertJsonPath('fulfillment_agreement.has_signed_pdf', false);
        $this->assertNull($show->json('fulfillment_agreement.accepted_at'));
        $show->assertJsonPath('tasks.10.id', 'fulfillment_agreement');
        $show->assertJsonPath('progress.total', 11);
    }

    public function test_agreement_preamble_uses_account_profile_and_signed_date(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Preamble Client Co',
            'email' => 'preamble@example.test',
            'street' => '390 Stovall St SE Unit 2411',
            'city' => 'Atlanta',
            'state' => 'GA',
            'zip' => '30316',
            'fulfillment_agreement_client_signed_at' => Carbon::parse('2026-07-16'),
        ]);

        $html = FulfillmentAgreementPreamble::html($account);

        $this->assertStringContainsString('July 16, 2026', $html);
        $this->assertStringContainsString('Preamble Client Co', $html);
        $this->assertStringContainsString('a <strong>GA</strong> entity', $html);
        $this->assertStringContainsString(
            '390 Stovall St SE Unit 2411 Atlanta, GA 30316',
            $html
        );
    }

    public function test_accept_endpoint_is_rejected_in_favor_of_upload_or_esign(): void
    {
        $this->seedTerms();

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Agreement Portal Co',
            'email' => 'agreement-portal@example.test',
        ]);
        $this->actingAsPortalUser($account);

        $accept = $this->postJson('/api/portal/onboarding/fulfillment-agreement/accept');
        $accept->assertStatus(422);
        $this->assertNull($account->fresh()->fulfillment_agreement_accepted_at);
    }

    public function test_blank_pdf_download_and_esign_completes_agreement(): void
    {
        Storage::fake('local');
        $this->seedTerms();

        $slack = Mockery::mock(SlackDeliveryService::class);
        $slack->shouldReceive('post')->once()->andReturn(['method' => 'webhook', 'channel' => '#alerts', 'ts' => null]);
        $this->app->instance(SlackDeliveryService::class, $slack);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Agreement Portal Co',
            'email' => 'agreement-portal@example.test',
        ]);
        $this->actingAsPortalUser($account);

        $pdf = $this->get('/api/portal/onboarding/fulfillment-agreement.pdf');
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));

        $tinyPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $esign = $this->postJson('/api/portal/onboarding/fulfillment-agreement/esign', [
            'company' => 'Agreement Portal Co',
            'rep_name' => 'Alex Client',
            'signed_at' => '2026-07-16',
            'signature_style' => 'dancing_script',
            'signature_text' => 'Alex Client',
            'signature_image' => $tinyPng,
        ]);
        $esign->assertOk();
        $esign->assertJsonPath('fulfillment_agreement.status', 'completed');
        $esign->assertJsonPath('fulfillment_agreement.method', 'esign');
        $esign->assertJsonPath('fulfillment_agreement.has_signed_pdf', true);
        $esign->assertJsonPath('tasks.10.status', 'completed');

        $account->refresh();
        $this->assertNotNull($account->fulfillment_agreement_accepted_at);
        $this->assertNotNull($account->fulfillment_agreement_path);
        Storage::disk('local')->assertExists($account->fulfillment_agreement_path);

        $signed = $this->get('/api/portal/onboarding/fulfillment-agreement/signed.pdf');
        $signed->assertOk();
    }

    public function test_upload_completes_agreement(): void
    {
        Storage::fake('local');
        $this->seedTerms();

        $slack = Mockery::mock(SlackDeliveryService::class);
        $slack->shouldReceive('post')->once()->andReturn(['method' => 'webhook', 'channel' => '#alerts', 'ts' => null]);
        $this->app->instance(SlackDeliveryService::class, $slack);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Upload Co',
            'email' => 'upload-portal@example.test',
        ]);
        $this->actingAsPortalUser($account);

        $file = UploadedFile::fake()->create('signed-agreement.pdf', 120, 'application/pdf');

        $upload = $this->post('/api/portal/onboarding/fulfillment-agreement/upload', [
            'file' => $file,
            'company' => 'Upload Co',
            'rep_name' => 'Sam Upload',
        ], ['Accept' => 'application/json']);

        $upload->assertOk();
        $upload->assertJsonPath('fulfillment_agreement.status', 'completed');
        $upload->assertJsonPath('fulfillment_agreement.method', 'upload');

        $account->refresh();
        $this->assertSame('upload', $account->fulfillment_agreement_method);
        Storage::disk('local')->assertExists($account->fulfillment_agreement_path);
    }
}
