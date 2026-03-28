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
    public function run(): void
    {
        $permissions = collect([
            ['key' => 'dashboard.view', 'label' => 'View dashboard', 'module' => 'dashboard'],
            ['key' => 'users.view', 'label' => 'View users', 'module' => 'users'],
            ['key' => 'users.create', 'label' => 'Create users', 'module' => 'users'],
            ['key' => 'users.update', 'label' => 'Update users', 'module' => 'users'],
            ['key' => 'users.delete', 'label' => 'Delete users', 'module' => 'users'],
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

        $admin->permissions()->sync($permissions->pluck('id'));
        $staff->permissions()->sync(
            $permissions->whereIn('key', ['dashboard.view', 'users.view', 'users.update'])->pluck('id')
        );

        $email = env('ADMIN_EMAIL', 'audi@saverack.com');
        $password = env('ADMIN_PASSWORD', 'J0rdan$123');
        $name = env('ADMIN_NAME', 'SaveRack Admin');

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
