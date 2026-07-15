<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Permission::ensureRowsForKeys([
            'returns.view',
            'returns.update',
        ]);

        $returnsIds = Permission::idsForKeys(['returns.view', 'returns.update']);
        if ($returnsIds !== []) {
            $admin = Role::query()->where('name', 'admin')->first();
            if ($admin !== null) {
                $admin->permissions()->syncWithoutDetaching($returnsIds);
            }
        }

        $inventoryViewId = Permission::query()->where('key', 'inventory.view')->value('id');
        $inventoryUpdateId = Permission::query()->where('key', 'inventory.update')->value('id');
        $returnsViewId = Permission::query()->where('key', 'returns.view')->value('id');
        $returnsUpdateId = Permission::query()->where('key', 'returns.update')->value('id');

        if ($returnsViewId && $inventoryViewId) {
            User::query()
                ->whereHas('permissions', fn ($q) => $q->where('permissions.id', $inventoryViewId))
                ->whereDoesntHave('permissions', fn ($q) => $q->where('permissions.id', $returnsViewId))
                ->each(function (User $user) use ($returnsViewId) {
                    $user->permissions()->attach($returnsViewId);
                });
        }

        if ($returnsUpdateId && $inventoryUpdateId) {
            User::query()
                ->whereHas('permissions', fn ($q) => $q->where('permissions.id', $inventoryUpdateId))
                ->whereDoesntHave('permissions', fn ($q) => $q->where('permissions.id', $returnsUpdateId))
                ->each(function (User $user) use ($returnsUpdateId) {
                    $user->permissions()->attach($returnsUpdateId);
                });
        }
    }

    public function down(): void
    {
        $returnsIds = Permission::query()
            ->whereIn('key', ['returns.view', 'returns.update'])
            ->pluck('id')
            ->all();

        if ($returnsIds !== []) {
            Role::query()->whereIn('name', ['admin', 'staff'])->each(function (Role $role) use ($returnsIds) {
                $role->permissions()->detach($returnsIds);
            });
        }
    }
};
