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
        $permissions = collect([
            ['key' => 'dashboard.view', 'label' => 'View dashboard', 'module' => 'dashboard'],
            ['key' => 'users.view', 'label' => 'View users', 'module' => 'users'],
            ['key' => 'users.create', 'label' => 'Create users', 'module' => 'users'],
            ['key' => 'users.update', 'label' => 'Update users', 'module' => 'users'],
            ['key' => 'users.delete', 'label' => 'Delete users', 'module' => 'users'],
            ['key' => 'tickets.view', 'label' => 'View tickets', 'module' => 'tickets'],
            ['key' => 'tickets.create', 'label' => 'Create tickets', 'module' => 'tickets'],
            ['key' => 'tickets.update', 'label' => 'Update tickets', 'module' => 'tickets'],
            ['key' => 'tickets.delete', 'label' => 'Delete tickets', 'module' => 'tickets'],
            ['key' => 'tickets.comment', 'label' => 'Comment on tickets', 'module' => 'tickets'],
            ['key' => 'webmaster.view', 'label' => 'View webmaster tasks', 'module' => 'webmaster'],
            ['key' => 'webmaster.create', 'label' => 'Create webmaster tasks', 'module' => 'webmaster'],
            ['key' => 'webmaster.update', 'label' => 'Update webmaster tasks', 'module' => 'webmaster'],
            ['key' => 'webmaster.delete', 'label' => 'Delete webmaster tasks', 'module' => 'webmaster'],
            ['key' => 'clients.view', 'label' => 'View client accounts', 'module' => 'clients'],
            ['key' => 'clients.create', 'label' => 'Create client accounts', 'module' => 'clients'],
            ['key' => 'clients.update', 'label' => 'Update client accounts', 'module' => 'clients'],
            ['key' => 'clients.delete', 'label' => 'Delete client accounts', 'module' => 'clients'],
            ['key' => 'client_users.view', 'label' => 'View client portal users', 'module' => 'client_users'],
            ['key' => 'client_users.create', 'label' => 'Create client portal users', 'module' => 'client_users'],
            ['key' => 'client_users.update', 'label' => 'Update client portal users', 'module' => 'client_users'],
            ['key' => 'client_users.delete', 'label' => 'Delete client portal users', 'module' => 'client_users'],
            ['key' => 'stores.view', 'label' => 'View client stores', 'module' => 'stores'],
            ['key' => 'stores.create', 'label' => 'Create client stores', 'module' => 'stores'],
            ['key' => 'stores.update', 'label' => 'Update client stores', 'module' => 'stores'],
            ['key' => 'stores.delete', 'label' => 'Delete client stores', 'module' => 'stores'],
            ['key' => 'billing.view', 'label' => 'View billing', 'module' => 'billing'],
            ['key' => 'billing.create', 'label' => 'Create invoices', 'module' => 'billing'],
            ['key' => 'billing.update', 'label' => 'Update invoices', 'module' => 'billing'],
            ['key' => 'billing.delete', 'label' => 'Delete draft invoices', 'module' => 'billing'],
            ['key' => 'inventory.view', 'label' => 'View inventory', 'module' => 'inventory'],
            ['key' => 'inventory.update', 'label' => 'Update inventory quantities', 'module' => 'inventory'],
        ])->map(function (array $p) {
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
        // Staff: dashboard + clients + view stores; other module access via direct user permissions.
        $staff->permissions()->sync(
            $permissions->whereIn('key', [
                'dashboard.view',
                'clients.view',
                'client_users.view',
                'stores.view',
            ])->pluck('id')
        );

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
