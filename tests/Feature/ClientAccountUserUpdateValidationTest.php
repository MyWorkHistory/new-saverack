<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientAccountUserUpdateValidationTest extends TestCase
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

    public function test_password_min_returns_clear_message(): void
    {
        $this->actingAsAdmin();

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Portal User Co',
            'email' => 'portal-user-co@example.test',
        ]);

        $portal = User::factory()->create([
            'name' => 'Portal Person',
            'email' => 'portal.person@example.test',
            'password' => Hash::make('old-password'),
            'client_account_id' => $account->id,
            'is_account_primary' => false,
            'status' => 'active',
        ]);

        $res = $this->patchJson(
            '/api/client-accounts/'.$account->id.'/account-users/'.$portal->id,
            [
                'name' => 'Portal Person',
                'status' => 'active',
                'password' => 'short',
                'password_confirmation' => 'short',
            ]
        );

        $res->assertStatus(422);
        $res->assertJsonPath('errors.password.0', 'Password must be at least 8 characters.');
    }
}
