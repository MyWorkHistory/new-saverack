<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RolePermissionSeeder extends Seeder
{
    /**
     * Create/update CRM admin from ADMIN_EMAIL / ADMIN_PASSWORD (default: on for non-production only).
     * Set SEED_ENV_ADMIN_USER=true on first production deploy if the database has no users yet.
     */
    private function shouldSeedEnvAdminUser(): bool
    {
        $v = env('SEED_ENV_ADMIN_USER');
        if ($v === null) {
            return ! app()->environment('production');
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Create demo staff1@…staff3@ accounts (default: on for non-production only).
     * Set SEED_DEMO_STAFF_USERS=true in .env for local dev; keep false on production.
     */
    private function shouldSeedDemoStaffUsers(): bool
    {
        $v = env('SEED_DEMO_STAFF_USERS');
        if ($v === null) {
            return ! app()->environment('production');
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public function run(): void
    {
        $permissions = collect(\App\Support\CrmStaffPermissionCatalog::definitions())->map(function (array $p) {
            return Permission::query()->firstOrCreate(
                ['key' => $p['key']],
                ['label' => $p['label'], 'module' => $p['module']]
            );
        });

        $admin = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );

        $staff = Role::query()->firstOrCreate(
            ['name' => 'staff'],
            ['label' => 'Staff', 'description' => 'Limited access', 'is_system' => true]
        );

        $client = Role::query()->firstOrCreate(
            ['name' => 'client'],
            [
                'label' => '3PL Client',
                'description' => 'Self-service / portal 3PL accounts',
                'is_system' => true,
            ]
        );
        $client->permissions()->sync([]);

        $admin->permissions()->sync($permissions->pluck('id'));
        // Copy any leftover staff-role matrix grants onto users before clearing the role.
        app(\App\Services\StaffPermissionMatrixService::class)->migrateRoleMatrixGrantsToDirect();
        // Staff CRM module access is assigned per user in the permissions matrix (not via role).
        $staff->permissions()->sync([]);

        if ($this->shouldSeedEnvAdminUser()) {
            $email = env('ADMIN_EMAIL', 'audi@saverack.com');
            $password = env('ADMIN_PASSWORD', 'J0rdan$123');
            $name = env('ADMIN_NAME', 'Audi Kowalski');

            $adminUser = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'status' => 'active',
                ]
            );

            $adminUser->roles()->sync([$admin->id]);
            UserProfile::query()->firstOrCreate(['user_id' => $adminUser->id]);
        }

        if ($this->shouldSeedDemoStaffUsers()) {
            $defaultStaffUsers = [
                ['name' => 'Staff One', 'email' => 'staff1@saverack.com', 'password' => 'Staff#1234'],
                ['name' => 'Staff Two', 'email' => 'staff2@saverack.com', 'password' => 'Staff#1234'],
                ['name' => 'Staff Three', 'email' => 'staff3@saverack.com', 'password' => 'Staff#1234'],
            ];

            foreach ($defaultStaffUsers as $staffUserData) {
                $staffUser = User::query()->updateOrCreate(
                    ['email' => $staffUserData['email']],
                    [
                        'name' => $staffUserData['name'],
                        'password' => Hash::make($staffUserData['password']),
                        'status' => 'active',
                    ]
                );

                $staffUser->roles()->sync([$staff->id]);
                UserProfile::query()->firstOrCreate(['user_id' => $staffUser->id]);
            }
        }
    }
}
