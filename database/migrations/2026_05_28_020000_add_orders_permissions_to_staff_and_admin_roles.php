<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Permission::ensureRowsForKeys(['orders.view', 'orders.update']);

        $ids = Permission::idsForKeys(['orders.view', 'orders.update']);
        if ($ids === []) {
            return;
        }

        $admin = Role::query()->where('name', 'admin')->first();
        if ($admin !== null) {
            $admin->permissions()->syncWithoutDetaching($ids);
        }

        $staff = Role::query()->where('name', 'staff')->first();
        if ($staff !== null) {
            $staff->permissions()->syncWithoutDetaching($ids);
        }
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('key', ['orders.view', 'orders.update'])->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        $admin = Role::query()->where('name', 'admin')->first();
        if ($admin !== null) {
            $admin->permissions()->detach($ids);
        }

        $staff = Role::query()->where('name', 'staff')->first();
        if ($staff !== null) {
            $staff->permissions()->detach($ids);
        }
    }
};
