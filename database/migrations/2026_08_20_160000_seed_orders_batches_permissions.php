<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\CrmStaffPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ensure Order Batches (and other new catalog subpages) have permission rows,
 * appear in the staff matrix, and are backfilled for anyone with legacy orders.* grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (CrmStaffPermissionCatalog::definitions() as $def) {
            Permission::query()->firstOrCreate(
                ['key' => $def['key']],
                ['label' => $def['label'], 'module' => $def['module']]
            );
        }

        $batchKeys = [
            'orders_batches.view',
            'orders_batches.create',
            'orders_batches.update',
            'orders_batches.delete',
        ];
        Permission::ensureRowsForKeys($batchKeys);
        $batchIds = Permission::idsForKeys($batchKeys);
        if ($batchIds === []) {
            return;
        }

        $actionMap = [
            'orders.view' => ['orders_batches.view'],
            'orders.create' => ['orders_batches.create'],
            'orders.update' => ['orders_batches.update', 'orders_batches.create', 'orders_batches.delete'],
            'orders.delete' => ['orders_batches.delete'],
        ];

        $allLegacy = array_keys($actionMap);
        $allChild = array_values(array_unique(array_merge(...array_values($actionMap))));
        Permission::ensureRowsForKeys(array_merge($allLegacy, $allChild));

        $idByKey = Permission::query()
            ->whereIn('key', array_merge($allLegacy, $allChild))
            ->pluck('id', 'key')
            ->all();

        DB::transaction(function () use ($actionMap, $idByKey, $batchIds) {
            foreach ($actionMap as $legacyKey => $childKeys) {
                $legacyId = isset($idByKey[$legacyKey]) ? (int) $idByKey[$legacyKey] : 0;
                if ($legacyId <= 0) {
                    continue;
                }

                $childIds = [];
                foreach ($childKeys as $ck) {
                    if (isset($idByKey[$ck])) {
                        $childIds[] = (int) $idByKey[$ck];
                    }
                }
                if ($childIds === []) {
                    continue;
                }

                $userIds = DB::table('permission_user')
                    ->where('permission_id', $legacyId)
                    ->pluck('user_id')
                    ->map(static fn ($id) => (int) $id)
                    ->all();

                foreach ($userIds as $userId) {
                    if ($userId <= 0) {
                        continue;
                    }
                    $user = User::query()->find($userId);
                    if (! $user instanceof User || $user->isAdministrator()) {
                        continue;
                    }
                    $user->permissions()->syncWithoutDetaching($childIds);
                }

                $roleIds = DB::table('permission_role')
                    ->where('permission_id', $legacyId)
                    ->pluck('role_id')
                    ->map(static fn ($id) => (int) $id)
                    ->all();

                foreach ($roleIds as $roleId) {
                    $role = Role::query()->find($roleId);
                    if (! $role instanceof Role) {
                        continue;
                    }
                    $role->permissions()->syncWithoutDetaching($childIds);
                }
            }

            $admin = Role::query()->where('name', 'admin')->first();
            if ($admin instanceof Role) {
                $admin->permissions()->syncWithoutDetaching($batchIds);
            }
        });

        Log::info('crm_staff_permissions.seeded_orders_batches', [
            'keys' => $batchKeys,
        ]);
    }

    public function down(): void
    {
        // Keep permission rows; do not strip grants (non-destructive).
    }
};
