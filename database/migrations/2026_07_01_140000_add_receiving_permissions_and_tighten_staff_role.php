<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Permission::ensureRowsForKeys([
            'receiving.view',
            'receiving.update',
        ]);

        $staff = Role::query()->where('name', 'staff')->first();
        if ($staff === null) {
            return;
        }

        $detachKeys = [
            'orders.view',
            'orders.update',
            'inventory.view',
            'inventory.update',
        ];
        $detachIds = Permission::query()->whereIn('key', $detachKeys)->pluck('id')->all();
        if ($detachIds !== []) {
            $staff->permissions()->detach($detachIds);
        }

        $admin = Role::query()->where('name', 'admin')->first();
        if ($admin !== null) {
            $receivingIds = Permission::idsForKeys(['receiving.view', 'receiving.update']);
            if ($receivingIds !== []) {
                $admin->permissions()->syncWithoutDetaching($receivingIds);
            }
        }
    }

    public function down(): void
    {
        $staff = Role::query()->where('name', 'staff')->first();
        if ($staff !== null) {
            $reattachIds = Permission::idsForKeys([
                'orders.view',
                'orders.update',
                'inventory.view',
                'inventory.update',
            ]);
            if ($reattachIds !== []) {
                $staff->permissions()->syncWithoutDetaching($reattachIds);
            }
        }

        $receivingIds = Permission::query()
            ->whereIn('key', ['receiving.view', 'receiving.update'])
            ->pluck('id')
            ->all();
        if ($receivingIds !== []) {
            Role::query()->whereIn('name', ['admin', 'staff'])->each(function (Role $role) use ($receivingIds) {
                $role->permissions()->detach($receivingIds);
            });
        }
    }
};
