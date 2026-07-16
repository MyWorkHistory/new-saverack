<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Staff role used to grant CRM matrix permissions (clients.view, etc.), which made the
 * permissions UI show locked checkboxes that could not be toggled/saved per user.
 * Move those grants onto each staff user as direct permissions, then clear them from the role.
 *
 * Runs only while the staff role still has matrix keys — never re-grants after detach,
 * so admin unchecks/saves are not overwritten on the next page load.
 */
class StaffPermissionMatrixService
{
    /**
     * Idempotent: copy staff-role CRM matrix keys to each staff user's direct grants,
     * then remove those keys from the staff role so the UI can edit them freely.
     */
    public function migrateRoleMatrixGrantsToDirect(): void
    {
        $staff = Role::query()->where('name', 'staff')->first();
        if (! $staff instanceof Role) {
            return;
        }

        $staff->loadMissing('permissions:id,key');
        $editable = array_flip(User::editableCrmPermissionKeys());

        $roleMatrixIds = [];
        $roleMatrixKeys = [];
        foreach ($staff->permissions as $permission) {
            $key = is_string($permission->key) ? trim($permission->key) : '';
            if ($key === '' || ! isset($editable[$key])) {
                continue;
            }
            $roleMatrixIds[] = (int) $permission->id;
            $roleMatrixKeys[] = $key;
        }

        // Nothing left on the role → already migrated; do not re-attach anything.
        if ($roleMatrixIds === []) {
            return;
        }

        $roleMatrixKeys = array_values(array_unique($roleMatrixKeys));
        $roleMatrixIds = array_values(array_unique($roleMatrixIds));

        try {
            Permission::ensureRowsForKeys($roleMatrixKeys);
            $idsToAttach = Permission::idsForKeys($roleMatrixKeys);
        } catch (\Throwable $e) {
            Log::warning('staff_permission_matrix.migrate_ids_failed', [
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if ($idsToAttach === []) {
            return;
        }

        DB::transaction(function () use ($staff, $idsToAttach, $roleMatrixIds) {
            $staffUserIds = DB::table('role_user')
                ->where('role_id', $staff->id)
                ->pluck('user_id')
                ->map(static function ($id) {
                    return (int) $id;
                })
                ->all();

            foreach ($staffUserIds as $userId) {
                if ($userId <= 0) {
                    continue;
                }
                $user = User::query()->find($userId);
                if (! $user instanceof User || $user->isAdministrator()) {
                    continue;
                }

                $existing = $user->permissions()->pluck('permissions.id')
                    ->map(static function ($id) {
                        return (int) $id;
                    })
                    ->all();
                $existingSet = array_flip($existing);
                $attachIds = [];
                foreach ($idsToAttach as $pid) {
                    $pid = (int) $pid;
                    if (! isset($existingSet[$pid])) {
                        $attachIds[] = $pid;
                    }
                }
                if ($attachIds !== []) {
                    $user->permissions()->syncWithoutDetaching($attachIds);
                }
            }

            $staff->permissions()->detach($roleMatrixIds);
        });

        Log::info('staff_permission_matrix.migrated_to_direct', [
            'keys' => $roleMatrixKeys,
            'staff_role_id' => (int) $staff->id,
        ]);
    }
}
