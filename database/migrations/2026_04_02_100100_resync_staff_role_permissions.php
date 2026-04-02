<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $staff = Role::query()->where('name', 'staff')->first();
        if (! $staff) {
            return;
        }

        $dashboard = Permission::query()->where('key', 'dashboard.view')->first();
        if (! $dashboard) {
            return;
        }

        $staff->permissions()->sync([$dashboard->id]);
    }

    public function down(): void
    {
        // Non-destructive: do not restore previous staff permission set.
    }
};
