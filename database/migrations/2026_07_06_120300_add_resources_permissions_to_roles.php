<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Permission::ensureRowsForKeys([
            'resources.view',
            'resources.create',
            'resources.update',
            'resources.delete',
        ]);

        $allIds = Permission::idsForKeys([
            'resources.view',
            'resources.create',
            'resources.update',
            'resources.delete',
        ]);

        $admin = Role::query()->where('name', 'admin')->first();
        if ($admin !== null && $allIds !== []) {
            $admin->permissions()->syncWithoutDetaching($allIds);
        }

        $staff = Role::query()->where('name', 'staff')->first();
        if ($staff !== null) {
            $viewIds = Permission::idsForKeys(['resources.view']);
            if ($viewIds !== []) {
                $staff->permissions()->syncWithoutDetaching($viewIds);
            }
        }
    }

    public function down(): void
    {
        $ids = Permission::query()
            ->whereIn('key', [
                'resources.view',
                'resources.create',
                'resources.update',
                'resources.delete',
            ])
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        Role::query()->whereIn('name', ['admin', 'staff'])->each(function (Role $role) use ($ids) {
            $role->permissions()->detach($ids);
        });
    }
};
