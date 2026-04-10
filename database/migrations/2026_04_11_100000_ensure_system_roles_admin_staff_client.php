<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Admin and staff roles are created in RolePermissionSeeder; the client role is created in a migration.
 * If migrations run without seeding (or roles were lost), only `client` may exist — breaking CRM staff.
 * This migration is idempotent: ensures all three system roles exist and restores default permission pivots.
 * It does not create, update, or delete users.
 */
return new class extends Migration
{
    /** @return list<string> */
    private function allSeededPermissionKeys(): array
    {
        return [
            'dashboard.view',
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'tickets.view',
            'tickets.create',
            'tickets.update',
            'tickets.delete',
            'tickets.comment',
            'webmaster.view',
            'webmaster.create',
            'webmaster.update',
            'webmaster.delete',
            'clients.view',
            'clients.create',
            'clients.update',
            'clients.delete',
            'client_users.view',
            'client_users.create',
            'client_users.update',
            'client_users.delete',
            'stores.view',
            'stores.create',
            'stores.update',
            'stores.delete',
            'billing.view',
            'billing.create',
            'billing.update',
            'billing.delete',
        ];
    }

    public function up(): void
    {
        Permission::ensureRowsForKeys($this->allSeededPermissionKeys());

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

        $admin->permissions()->sync(Permission::query()->pluck('id'));

        $staffKeys = [
            'dashboard.view',
            'clients.view',
            'client_users.view',
            'stores.view',
        ];
        $staff->permissions()->sync(Permission::idsForKeys($staffKeys));

        $client->permissions()->sync([]);
    }

    public function down(): void
    {
        // Intentionally non-destructive: do not remove roles created for production recovery.
    }
};
