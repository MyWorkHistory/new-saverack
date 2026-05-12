<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill: attach ShipHero orders permissions to `staff` for databases that ran
 * `2026_04_11_100000_ensure_system_roles_admin_staff_client` before inventory keys were included in $staffKeys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::ensureRowsForKeys(['inventory.view', 'inventory.update']);

        $staff = Role::query()->where('name', 'staff')->first();
        if ($staff === null) {
            return;
        }

        $staff->permissions()->syncWithoutDetaching(Permission::idsForKeys(['inventory.view', 'inventory.update']));
    }

    public function down(): void
    {
        $staff = Role::query()->where('name', 'staff')->first();
        if ($staff === null) {
            return;
        }

        $ids = Permission::query()->whereIn('key', ['inventory.view', 'inventory.update'])->pluck('id')->all();
        if ($ids !== []) {
            $staff->permissions()->detach($ids);
        }
    }
};
