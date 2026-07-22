<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\TermsOfService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFulfillmentAgreementVerifyApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): User
    {
        $update = Permission::query()->firstOrCreate(
            ['key' => 'clients.update'],
            ['label' => 'Update clients', 'module' => 'clients']
        );
        $view = Permission::query()->firstOrCreate(
            ['key' => 'clients.view'],
            ['label' => 'View clients', 'module' => 'clients']
        );
        $user = User::factory()->create([
            'client_account_id' => null,
        ]);
        $user->permissions()->attach([$update->id, $view->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_staff_can_counter_sign_and_verify_esign_agreement(): void
    {
        Storage::fake('local');
        TermsOfService::query()->create(['body' => '<p>Terms</p>']);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Verify Co',
            'email' => 'verify@example.test',
            'fulfillment_agreement_accepted_at' => now(),
            'fulfillment_agreement_method' => 'esign',
            'fulfillment_agreement_company' => 'Verify Co',
            'fulfillment_agreement_rep_name' => 'Client Rep',
            'fulfillment_agreement_client_signed_at' => now(),
            'fulfillment_agreement_client_signature' => json_encode([
                'style' => 'dancing_script',
                'text' => 'Client Rep',
            ]),
            'fulfillment_agreement_path' => 'fulfillment-agreements/1/placeholder.pdf',
            'fulfillment_agreement_original_name' => 'placeholder.pdf',
            'fulfillment_agreement_mime' => 'application/pdf',
        ]);
        Storage::disk('local')->put($account->fulfillment_agreement_path, '%PDF-1.4 placeholder');

        $this->actingAsStaff();

        $tinyPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $response = $this->postJson(
            '/api/client-accounts/'.$account->id.'/onboarding/fulfillment-agreement/verify',
            [
                'rep_name' => 'Audi Kowalski',
                'signed_at' => '2026-07-16',
                'signature_style' => 'great_vibes',
                'signature_text' => 'Audi Kowalski',
                'signature_image' => $tinyPng,
            ]
        );

        $response->assertOk();
        $task = collect($response->json('tasks'))->firstWhere('id', 'fulfillment_agreement');
        $this->assertNotNull($task);
        $this->assertTrue($task['verified'] ?? false);
        $response->assertJsonPath('fulfillment_agreement.staff_counter_signed', true);
        $response->assertJsonPath('fulfillment_agreement.staff_rep_name', 'Audi Kowalski');

        $account->refresh();
        $this->assertNotNull($account->fulfillment_agreement_staff_signed_at);
        Storage::disk('local')->assertExists($account->fulfillment_agreement_path);

        $pdf = $this->get('/api/client-accounts/'.$account->id.'/onboarding/fulfillment-agreement/signed.pdf');
        $pdf->assertOk();
    }

    public function test_staff_verify_upload_marks_verified_without_counter_sign(): void
    {
        Storage::fake('local');
        TermsOfService::query()->create(['body' => '<p>Terms</p>']);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Upload Verify Co',
            'email' => 'upload-verify@example.test',
            'fulfillment_agreement_accepted_at' => now(),
            'fulfillment_agreement_method' => 'upload',
            'fulfillment_agreement_company' => 'Upload Verify Co',
            'fulfillment_agreement_rep_name' => 'Sam Upload',
            'fulfillment_agreement_client_signed_at' => now(),
            'fulfillment_agreement_client_signature' => json_encode([
                'style' => 'upload',
                'text' => 'Manually signed (uploaded PDF)',
            ]),
            'fulfillment_agreement_path' => 'fulfillment-agreements/1/upload-original.pdf',
            'fulfillment_agreement_original_name' => 'signed-agreement.pdf',
            'fulfillment_agreement_mime' => 'application/pdf',
        ]);
        Storage::disk('local')->put($account->fulfillment_agreement_path, '%PDF-1.4 wet-ink-upload');

        $this->actingAsStaff();

        $response = $this->postJson(
            '/api/client-accounts/'.$account->id.'/onboarding/fulfillment-agreement/verify',
            []
        );

        $response->assertOk();
        $task = collect($response->json('tasks'))->firstWhere('id', 'fulfillment_agreement');
        $this->assertNotNull($task);
        $this->assertTrue($task['verified'] ?? false);
        $response->assertJsonPath('fulfillment_agreement.staff_counter_signed', false);

        $account->refresh();
        $this->assertNull($account->fulfillment_agreement_staff_signed_at);
        $this->assertNull($account->fulfillment_agreement_staff_signature);
        $this->assertSame('upload', $account->fulfillment_agreement_method);
        Storage::disk('local')->assertExists($account->fulfillment_agreement_path);
        $this->assertSame('%PDF-1.4 wet-ink-upload', Storage::disk('local')->get($account->fulfillment_agreement_path));

        $pdf = $this->get('/api/client-accounts/'.$account->id.'/onboarding/fulfillment-agreement/signed.pdf');
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
    }

    public function test_staff_verify_upload_ignores_signature_payload(): void
    {
        Storage::fake('local');
        TermsOfService::query()->create(['body' => '<p>Terms</p>']);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Upload No Sign Co',
            'email' => 'upload-nosign@example.test',
            'fulfillment_agreement_accepted_at' => now(),
            'fulfillment_agreement_method' => 'upload',
            'fulfillment_agreement_path' => 'fulfillment-agreements/1/upload-original.pdf',
            'fulfillment_agreement_mime' => 'application/pdf',
        ]);
        Storage::disk('local')->put($account->fulfillment_agreement_path, '%PDF-1.4 wet-ink');

        $this->actingAsStaff();

        // Upload path ignores signature fields and verifies without counter-sign.
        $tinyPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $response = $this->postJson(
            '/api/client-accounts/'.$account->id.'/onboarding/fulfillment-agreement/verify',
            [
                'rep_name' => 'Audi Kowalski',
                'signature_style' => 'great_vibes',
                'signature_text' => 'Audi Kowalski',
                'signature_image' => $tinyPng,
            ]
        );

        $response->assertOk();
        $account->refresh();
        $this->assertNull($account->fulfillment_agreement_staff_signed_at);
        $this->assertTrue(
            collect($response->json('tasks'))->firstWhere('id', 'fulfillment_agreement')['verified'] ?? false
        );
    }

    public function test_removing_verification_on_upload_keeps_uploaded_pdf(): void
    {
        Storage::fake('local');
        TermsOfService::query()->create(['body' => '<p>Terms</p>']);

        $path = 'fulfillment-agreements/1/upload-original.pdf';
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Upload Unverify Co',
            'email' => 'upload-unverify@example.test',
            'fulfillment_agreement_accepted_at' => now(),
            'fulfillment_agreement_method' => 'upload',
            'fulfillment_agreement_client_signed_at' => now(),
            'fulfillment_agreement_path' => $path,
            'fulfillment_agreement_original_name' => 'signed-agreement.pdf',
            'fulfillment_agreement_mime' => 'application/pdf',
            'onboarding_verifications' => [
                'fulfillment_agreement' => [
                    'verified_at' => now()->toIso8601String(),
                    'verified_by' => 1,
                ],
            ],
        ]);
        Storage::disk('local')->put($path, '%PDF-1.4 wet-ink-keep');

        $this->actingAsStaff();

        $response = $this->deleteJson(
            '/api/client-accounts/'.$account->id.'/onboarding/fulfillment-agreement/verify'
        );

        $response->assertOk();
        $response->assertJsonPath('fulfillment_agreement.status', 'completed');
        $response->assertJsonPath('fulfillment_agreement.staff_counter_signed', false);
        $task = collect($response->json('tasks'))->firstWhere('id', 'fulfillment_agreement');
        $this->assertFalse($task['verified'] ?? true);

        $account->refresh();
        $this->assertSame('upload', $account->fulfillment_agreement_method);
        $this->assertNotNull($account->fulfillment_agreement_accepted_at);
        $this->assertNull($account->fulfillment_agreement_staff_signed_at);
        Storage::disk('local')->assertExists($path);
        $this->assertSame('%PDF-1.4 wet-ink-keep', Storage::disk('local')->get($path));
    }

    public function test_removing_verification_clears_staff_signature_and_keeps_client_completion(): void
    {
        Storage::fake('local');
        TermsOfService::query()->create(['body' => '<p>Terms</p>']);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Remove Verification Co',
            'email' => 'remove-verification@example.test',
            'fulfillment_agreement_accepted_at' => now(),
            'fulfillment_agreement_method' => 'esign',
            'fulfillment_agreement_company' => 'Remove Verification Co',
            'fulfillment_agreement_rep_name' => 'Client Rep',
            'fulfillment_agreement_client_signed_at' => now(),
            'fulfillment_agreement_client_signature' => json_encode([
                'style' => 'dancing_script',
                'text' => 'Client Rep',
            ]),
            'fulfillment_agreement_staff_rep_name' => 'Audi Kowalski',
            'fulfillment_agreement_staff_signed_at' => now(),
            'fulfillment_agreement_staff_signature' => json_encode([
                'style' => 'great_vibes',
                'text' => 'Audi Kowalski',
                'image_path' => 'fulfillment-agreements/1/staff-signature.png',
            ]),
            'fulfillment_agreement_path' => 'fulfillment-agreements/1/fulfillment-agreement-signed.pdf',
            'fulfillment_agreement_original_name' => 'fulfillment-agreement-signed.pdf',
            'fulfillment_agreement_mime' => 'application/pdf',
            'onboarding_verifications' => [
                'fulfillment_agreement' => [
                    'verified_at' => now()->toIso8601String(),
                    'verified_by' => 1,
                ],
            ],
        ]);
        Storage::disk('local')->put(
            'fulfillment-agreements/'.$account->id.'/staff-signature.png',
            'image'
        );

        $this->actingAsStaff();

        $response = $this->deleteJson(
            '/api/client-accounts/'.$account->id.'/onboarding/fulfillment-agreement/verify'
        );

        $response->assertOk();
        $response->assertJsonPath('fulfillment_agreement.status', 'completed');
        $response->assertJsonPath('fulfillment_agreement.staff_counter_signed', false);
        $task = collect($response->json('tasks'))->firstWhere('id', 'fulfillment_agreement');
        $this->assertFalse($task['verified'] ?? true);

        $account->refresh();
        $this->assertNotNull($account->fulfillment_agreement_client_signed_at);
        $this->assertNull($account->fulfillment_agreement_staff_signed_at);
        $this->assertNull($account->fulfillment_agreement_staff_signature);
        Storage::disk('local')->assertMissing(
            'fulfillment-agreements/'.$account->id.'/staff-signature.png'
        );
        Storage::disk('local')->assertExists($account->fulfillment_agreement_path);
    }

    public function test_staff_can_clear_agreement_back_to_not_completed(): void
    {
        Storage::fake('local');
        TermsOfService::query()->create(['body' => '<p>Terms</p>']);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_PENDING,
            'company_name' => 'Clear Agreement Co',
            'email' => 'clear-agreement@example.test',
            'fulfillment_agreement_accepted_at' => now(),
            'fulfillment_agreement_method' => 'esign',
            'fulfillment_agreement_company' => 'Clear Agreement Co',
            'fulfillment_agreement_rep_name' => 'Client Rep',
            'fulfillment_agreement_client_signed_at' => now(),
            'fulfillment_agreement_client_signature' => json_encode([
                'style' => 'dancing_script',
                'text' => 'Client Rep',
            ]),
            'fulfillment_agreement_staff_rep_name' => 'Audi Kowalski',
            'fulfillment_agreement_staff_signed_at' => now(),
            'fulfillment_agreement_staff_signature' => json_encode([
                'style' => 'great_vibes',
                'text' => 'Audi Kowalski',
            ]),
            'fulfillment_agreement_path' => 'fulfillment-agreements/1/fulfillment-agreement-signed.pdf',
            'fulfillment_agreement_original_name' => 'fulfillment-agreement-signed.pdf',
            'fulfillment_agreement_mime' => 'application/pdf',
            'onboarding_verifications' => [
                'fulfillment_agreement' => [
                    'verified_at' => now()->toIso8601String(),
                    'verified_by' => 1,
                ],
            ],
        ]);
        $directory = 'fulfillment-agreements/'.$account->id;
        Storage::disk('local')->put($directory.'/fulfillment-agreement-signed.pdf', 'pdf');
        Storage::disk('local')->put($directory.'/client-signature.png', 'image');
        Storage::disk('local')->put($directory.'/staff-signature.png', 'image');

        $this->actingAsStaff();

        $response = $this->deleteJson(
            '/api/client-accounts/'.$account->id.'/onboarding/fulfillment-agreement'
        );

        $response->assertOk();
        $response->assertJsonPath('fulfillment_agreement.status', 'not_completed');
        $response->assertJsonPath('fulfillment_agreement.has_signed_pdf', false);
        $task = collect($response->json('tasks'))->firstWhere('id', 'fulfillment_agreement');
        $this->assertSame('not_completed', $task['status'] ?? null);
        $this->assertFalse($task['verified'] ?? true);

        $account->refresh();
        $this->assertNull($account->fulfillment_agreement_accepted_at);
        $this->assertNull($account->fulfillment_agreement_client_signed_at);
        $this->assertNull($account->fulfillment_agreement_client_signature);
        $this->assertNull($account->fulfillment_agreement_staff_signed_at);
        $this->assertNull($account->fulfillment_agreement_staff_signature);
        $this->assertNull($account->fulfillment_agreement_path);
        Storage::disk('local')->assertMissing($directory);
    }
}
