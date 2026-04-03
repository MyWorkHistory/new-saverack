<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $defs = [
            ['key' => 'stores.view', 'label' => 'View client stores', 'module' => 'stores'],
            ['key' => 'stores.create', 'label' => 'Create client stores', 'module' => 'stores'],
            ['key' => 'stores.update', 'label' => 'Update client stores', 'module' => 'stores'],
            ['key' => 'stores.delete', 'label' => 'Delete client stores', 'module' => 'stores'],
        ];

        foreach ($defs as $p) {
            Permission::query()->firstOrCreate(
                ['key' => $p['key']],
                ['label' => $p['label'], 'module' => $p['module']]
            );
        }

        $admin = Role::query()->where('name', 'admin')->first();
        if ($admin !== null) {
            $admin->permissions()->sync(Permission::query()->pluck('id'));
        }

        $staff = Role::query()->where('name', 'staff')->first();
        if ($staff !== null) {
            $staffKeys = ['dashboard.view', 'clients.view', 'stores.view'];
            $staff->permissions()->sync(
                Permission::query()->whereIn('key', $staffKeys)->pluck('id')
            );
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('key', [
            'stores.view',
            'stores.create',
            'stores.update',
            'stores.delete',
        ])->delete();
    }
};
