<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\CrmStaffPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $expandMap = [];
        foreach (CrmStaffPermissionCatalog::legacyToChildren() as $legacy => $children) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $legacyKey = $legacy.'.'.$action;
                $childKeys = [];
                foreach ($children as $child) {
                    $childKeys[] = $child.'.'.$action;
                }
                $expandMap[$legacyKey] = $childKeys;
            }
        }

        Permission::ensureRowsForKeys(array_values(array_unique(array_merge(
            array_keys($expandMap),
            ...array_values($expandMap)
        ))));

        $idByKey = Permission::query()
            ->whereIn('key', array_values(array_unique(array_merge(
                array_keys($expandMap),
                ...array_values($expandMap)
            ))))
            ->pluck('id', 'key')
            ->all();

        DB::transaction(function () use ($expandMap, $idByKey) {
            foreach ($expandMap as $legacyKey => $childKeys) {
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

                // Users with the legacy direct grant → attach children.
                $userIds = DB::table('permission_user')
                    ->where('permission_id', $legacyId)
                    ->pluck('user_id')
                    ->map(static function ($id) {
                        return (int) $id;
                    })
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

                // Roles with legacy grant → attach children (admin keeps everything).
                $roleIds = DB::table('permission_role')
                    ->where('permission_id', $legacyId)
                    ->pluck('role_id')
                    ->map(static function ($id) {
                        return (int) $id;
                    })
                    ->all();

                foreach ($roleIds as $roleId) {
                    $role = Role::query()->find($roleId);
                    if (! $role instanceof Role) {
                        continue;
                    }
                    $role->permissions()->syncWithoutDetaching($childIds);
                }
            }
        });

        Log::info('crm_staff_permissions.expanded_subpages', [
            'legacy_keys' => array_keys($expandMap),
        ]);
    }

    public function down(): void
    {
        // Keep new permission rows; do not strip grants (non-destructive).
    }
};
