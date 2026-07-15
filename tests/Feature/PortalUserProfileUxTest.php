<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalUserProfileUxTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);
        $admin = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );
        $user->roles()->attach($admin->id);
        Sanctum::actingAs($user);

        return $user;
    }

    private function portalAccountAndUser(array $userAttrs = []): array
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Portal UX Co',
            'email' => 'portal-ux-co@example.test',
        ]);

        $portal = User::factory()->create(array_merge([
            'name' => 'Portal UX User',
            'email' => 'portal.ux@example.test',
            'password' => Hash::make('password-secret'),
            'client_account_id' => $account->id,
            'is_account_primary' => false,
            'status' => 'active',
        ], $userAttrs));

        return [$account, $portal];
    }

    public function test_inactive_portal_user_cannot_login(): void
    {
        [, $portal] = $this->portalAccountAndUser(['status' => 'inactive']);

        $this->postJson('/api/login', [
            'email' => $portal->email,
            'password' => 'password-secret',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'User account is not active.');
    }

    public function test_active_portal_user_can_login(): void
    {
        [, $portal] = $this->portalAccountAndUser(['status' => 'active']);

        $this->postJson('/api/login', [
            'email' => $portal->email,
            'password' => 'password-secret',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_create_portal_user_defaults_to_active_and_rejects_pending(): void
    {
        $this->actingAsAdmin();
        [$account] = $this->portalAccountAndUser();

        $create = $this->postJson('/api/client-accounts/'.$account->id.'/account-users', [
            'name' => 'New CS User',
            'email' => 'new.cs@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
            'phone' => '555-0100',
        ]);

        $create->assertSuccessful();
        $create->assertJsonPath('status', 'active');
        $create->assertJsonPath('phone', '555-0100');

        $this->postJson('/api/client-accounts/'.$account->id.'/account-users', [
            'name' => 'Pending Attempt',
            'email' => 'pending.attempt@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'pending',
        ])->assertStatus(422);

        $this->patchJson(
            '/api/client-accounts/'.$account->id.'/account-users/'.$create->json('id'),
            ['status' => 'pending']
        )->assertStatus(422);
    }

    public function test_notes_can_be_listed_created_and_deleted(): void
    {
        $admin = $this->actingAsAdmin();
        [$account, $portal] = $this->portalAccountAndUser();

        $this->getJson('/api/client-accounts/'.$account->id.'/account-users/'.$portal->id.'/notes')
            ->assertOk()
            ->assertJsonPath('notes', []);

        $created = $this->postJson(
            '/api/client-accounts/'.$account->id.'/account-users/'.$portal->id.'/notes',
            ['body' => 'Internal staff note']
        )->assertCreated();

        $noteId = $created->json('id');
        $this->assertNotEmpty($noteId);
        $this->assertDatabaseHas('user_notes', [
            'id' => $noteId,
            'user_id' => $portal->id,
            'author_id' => $admin->id,
            'body' => 'Internal staff note',
        ]);

        $this->getJson('/api/client-accounts/'.$account->id.'/account-users/'.$portal->id.'/notes')
            ->assertOk()
            ->assertJsonPath('notes.0.id', $noteId);

        $this->deleteJson(
            '/api/client-accounts/'.$account->id.'/account-users/'.$portal->id.'/notes/'.$noteId
        )->assertOk();

        $this->assertDatabaseMissing('user_notes', ['id' => $noteId]);
    }

    public function test_update_personal_info_accepts_phone(): void
    {
        $this->actingAsAdmin();
        [$account, $portal] = $this->portalAccountAndUser();

        $this->patchJson(
            '/api/client-accounts/'.$account->id.'/account-users/'.$portal->id,
            [
                'name' => 'Portal UX User',
                'phone' => '555-0199',
            ]
        )
            ->assertOk()
            ->assertJsonPath('phone', '555-0199');
    }
}
