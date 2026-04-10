<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $defs = [
            ['key' => 'billing.view', 'label' => 'View billing', 'module' => 'billing'],
            ['key' => 'billing.create', 'label' => 'Create invoices', 'module' => 'billing'],
            ['key' => 'billing.update', 'label' => 'Update invoices', 'module' => 'billing'],
            ['key' => 'billing.delete', 'label' => 'Delete draft invoices', 'module' => 'billing'],
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
    }

    public function down(): void
    {
        // Do not delete permission rows: `permission_role.permission_id` uses ON DELETE CASCADE,
        // which would strip pivots (and effectively break every role) for those permissions.
    }
};
