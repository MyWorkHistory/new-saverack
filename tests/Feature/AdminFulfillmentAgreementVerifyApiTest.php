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

    public function test_staff_verify_upload_preserves_wet_ink_and_builds_composite_pdf(): void
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
                'upload_path' => 'fulfillment-agreements/1/upload-original.pdf',
            ]),
            'fulfillment_agreement_path' => 'fulfillment-agreements/1/upload-original.pdf',
            'fulfillment_agreement_original_name' => 'signed-agreement.pdf',
            'fulfillment_agreement_mime' => 'application/pdf',
        ]);
        Storage::disk('local')->put($account->fulfillment_agreement_path, '%PDF-1.4 wet-ink-upload');

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
        $account->refresh();

        $this->assertNotNull($account->fulfillment_agreement_staff_signed_at);
        $this->assertSame('Audi Kowalski', $account->fulfillment_agreement_staff_rep_name);
        Storage::disk('local')->assertExists($account->fulfillment_agreement_path);
        Storage::disk('local')->assertExists(
            'fulfillment-agreements/'.$account->id.'/client-wet-ink.pdf'
        );

        $meta = json_decode((string) $account->fulfillment_agreement_client_signature, true);
        $this->assertIsArray($meta);
        $this->assertSame(
            'fulfillment-agreements/'.$account->id.'/client-wet-ink.pdf',
            $meta['upload_path'] ?? null
        );

        $pdf = $this->get('/api/client-accounts/'.$account->id.'/onboarding/fulfillment-agreement/signed.pdf');
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
    }
}
