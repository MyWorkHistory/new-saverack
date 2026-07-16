<?php

use App\Models\Permission;
use App\Models\User;
use App\Support\CrmStaffPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unlock Create/Delete on Orders / Receiving / Returns (and Inventory) subpages.
 * Staff who already have *.update on a subpage also get create + delete so access is unchanged.
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

        $prefixes = [];
        foreach (CrmStaffPermissionCatalog::legacyToChildren() as $legacy => $children) {
            if (! in_array($legacy, CrmStaffPermissionCatalog::opsModulesWhereUpdateImpliesMutations(), true)) {
                continue;
            }
            foreach ($children as $child) {
                $prefixes[] = $child;
            }
        }

        $pairs = [];
        foreach ($prefixes as $prefix) {
            $pairs[$prefix.'.update'] = [
                $prefix.'.create',
                $prefix.'.delete',
            ];
        }
        // Legacy coarse update → create/delete as well.
        foreach (CrmStaffPermissionCatalog::opsModulesWhereUpdateImpliesMutations() as $legacy) {
            $pairs[$legacy.'.update'] = [
                $legacy.'.create',
                $legacy.'.delete',
            ];
        }

        $allKeys = [];
        foreach ($pairs as $from => $tos) {
            $allKeys[] = $from;
            foreach ($tos as $to) {
                $allKeys[] = $to;
            }
        }
        Permission::ensureRowsForKeys(array_values(array_unique($allKeys)));

        $idByKey = Permission::query()
            ->whereIn('key', array_values(array_unique($allKeys)))
            ->pluck('id', 'key')
            ->all();

        DB::transaction(function () use ($pairs, $idByKey) {
            foreach ($pairs as $fromKey => $toKeys) {
                $fromId = isset($idByKey[$fromKey]) ? (int) $idByKey[$fromKey] : 0;
                if ($fromId <= 0) {
                    continue;
                }
                $toIds = [];
                foreach ($toKeys as $toKey) {
                    if (isset($idByKey[$toKey])) {
                        $toIds[] = (int) $idByKey[$toKey];
                    }
                }
                if ($toIds === []) {
                    continue;
                }

                $userIds = DB::table('permission_user')
                    ->where('permission_id', $fromId)
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
                    $user->permissions()->syncWithoutDetaching($toIds);
                }

                $roleIds = DB::table('permission_role')
                    ->where('permission_id', $fromId)
                    ->pluck('role_id')
                    ->map(static function ($id) {
                        return (int) $id;
                    })
                    ->all();

                foreach ($roleIds as $roleId) {
                    $role = \App\Models\Role::query()->find($roleId);
                    if ($role) {
                        $role->permissions()->syncWithoutDetaching($toIds);
                    }
                }
            }
        });

        Log::info('crm_staff_permissions.unlocked_create_delete_ops', [
            'prefixes' => $prefixes,
        ]);
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
