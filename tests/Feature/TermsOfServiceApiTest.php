<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Role;
use App\Models\TermsOfService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TermsOfServiceApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $admin = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );
        $user->roles()->attach($admin->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_view_settings_terms(): void
    {
        $this->getJson('/api/settings/terms-of-service')->assertUnauthorized();
    }

    public function test_admin_can_update_and_read_global_terms(): void
    {
        $this->actingAsAdmin();

        $put = $this->putJson('/api/settings/terms-of-service', [
            'body' => '<h2>Terms</h2><p>Hello <strong>world</strong></p><ul><li>One</li></ul><script>alert(1)</script>',
        ]);
        $put->assertOk();
        $put->assertJsonPath('body', '<h2>Terms</h2><p>Hello <strong>world</strong></p><ul><li>One</li></ul>');
        $this->assertStringNotContainsString('script', (string) $put->json('body'));

        $get = $this->getJson('/api/settings/terms-of-service');
        $get->assertOk();
        $get->assertJsonPath('body', '<h2>Terms</h2><p>Hello <strong>world</strong></p><ul><li>One</li></ul>');
    }

    public function test_account_inherits_global_terms_until_override(): void
    {
        $this->actingAsAdmin();

        TermsOfService::query()->create([
            'body' => '<p>Global default</p>',
        ]);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Terms Client',
            'email' => 'terms-client@example.test',
        ]);

        $inherited = $this->getJson('/api/client-accounts/'.$account->id.'/terms-of-service');
        $inherited->assertOk();
        $inherited->assertJsonPath('body', '<p>Global default</p>');
        $inherited->assertJsonPath('is_override', false);

        $override = $this->putJson('/api/client-accounts/'.$account->id.'/terms-of-service', [
            'body' => '<p>Account only</p>',
        ]);
        $override->assertOk();
        $override->assertJsonPath('body', '<p>Account only</p>');
        $override->assertJsonPath('is_override', true);

        $this->putJson('/api/settings/terms-of-service', [
            'body' => '<p>Updated global</p>',
        ])->assertOk();

        $stillOverride = $this->getJson('/api/client-accounts/'.$account->id.'/terms-of-service');
        $stillOverride->assertJsonPath('body', '<p>Account only</p>');
        $stillOverride->assertJsonPath('is_override', true);

        $other = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Other Client',
            'email' => 'other-terms@example.test',
        ]);
        $this->getJson('/api/client-accounts/'.$other->id.'/terms-of-service')
            ->assertJsonPath('body', '<p>Updated global</p>');
    }

    public function test_public_terms_pages_render_body(): void
    {
        TermsOfService::query()->create([
            'body' => '<p>Public global terms</p>',
        ]);
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Public Terms Co',
            'email' => 'public-terms@example.test',
            'terms_of_service_body' => '<p>Public account terms</p>',
        ]);

        $this->get('/terms')
            ->assertOk()
            ->assertSee('Public global terms', false)
            ->assertSee('logo.jpg', false);

        $this->get('/terms/accounts/'.$account->id)
            ->assertOk()
            ->assertSee('Public account terms', false);
    }
}
