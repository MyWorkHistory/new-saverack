<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserBulkDeleteRequest;
use App\Http\Requests\UserBulkUpdateRequest;
use App\Http\Requests\UserPermissionsUpdateRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\User;
use App\Support\CrmActivityPresenter;
use App\Support\UserStaffHistory;
use App\Services\UserAvatarService;
use App\Services\UserService;
use App\Support\CsvExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    /** @var UserService */
    protected $userService;

    /** @var UserAvatarService */
    protected $avatars;

    public function __construct(UserService $userService, UserAvatarService $avatars)
    {
        $this->userService = $userService;
        $this->avatars = $avatars;
        $this->authorizeResource(User::class, 'user');
    }

    public function bulkUpdate(UserBulkUpdateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ids = $validated['user_ids'];
        $status = null;
        if (array_key_exists('status', $validated) && $validated['status'] !== null && $validated['status'] !== '') {
            $status = $validated['status'];
        }
        $roleIds = null;
        if (array_key_exists('role_ids', $validated)) {
            $roleIds = $validated['role_ids'];
        }

        foreach ($ids as $id) {
            $this->authorize('update', User::findOrFail($id));
        }

        $updated = $this->userService->bulkUpdateStatusAndRoles($ids, $status, $roleIds, $request->user());

        return response()->json(['message' => 'Users updated.', 'updated' => $updated]);
    }

    public function bulkDestroy(UserBulkDeleteRequest $request): JsonResponse
    {
        $ids = array_values(array_unique(array_map('intval', $request->validated()['user_ids'])));
        $actor = $request->user();
        $deleted = 0;

        foreach ($ids as $id) {
            $user = User::query()->find($id);
            if ($user === null) {
                continue;
            }
            if ($user->client_account_id !== null) {
                continue;
            }
            if ($actor !== null && $actor->id === $user->id) {
                continue;
            }
            $this->authorize('delete', $user);
            $this->userService->delete($user, $actor);
            $deleted++;
        }

        return response()->json([
            'message' => $deleted > 0 ? 'Users deleted.' : 'No users deleted.',
            'deleted' => $deleted,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->paginate($request->only([
            'search',
            'per_page',
            'page',
            'sort_by',
            'sort_dir',
            'role_id',
            'status',
            'plan',
        ]));

        return response()->json($users);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->only(['search', 'role_id', 'status', 'plan']);
        $query = $this->userService->staffExportQuery($filters);

        $userColumns = array_values(array_diff(
            Schema::getColumnListing('users'),
            ['password', 'remember_token'],
        ));
        $profileColumns = array_values(array_diff(
            Schema::getColumnListing('user_profiles'),
            ['id', 'user_id'],
        ));
        $profileHeaders = array_map(
            static fn (string $c): string => 'user_profile_'.$c,
            $profileColumns,
        );
        $headers = array_merge($userColumns, $profileHeaders, ['roles']);

        $filename = 'staff-export-'.date('Y-m-d').'.csv';

        return CsvExporter::stream($filename, $headers, function ($out) use ($query, $userColumns, $profileColumns) {
            $query->chunk(500, function ($users) use ($out, $userColumns, $profileColumns) {
                foreach ($users as $user) {
                    $row = [];
                    foreach ($userColumns as $col) {
                        $row[] = CsvExporter::cell($user->getAttribute($col));
                    }
                    $profile = $user->profile;
                    foreach ($profileColumns as $col) {
                        $row[] = CsvExporter::cell(
                            $profile !== null ? $profile->getAttribute($col) : null
                        );
                    }
                    $roleText = $user->roles
                        ->map(static function ($r) {
                            $label = $r->label ?? null;
                            $name = $r->name ?? null;

                            return (string) (($label !== null && $label !== '') ? $label : ($name ?? ''));
                        })
                        ->filter(static fn (string $s) => $s !== '')
                        ->implode('; ');
                    $row[] = $roleText;
                    fputcsv($out, $row);
                }
            });
        });
    }

    public function store(UserStoreRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated(), $request->user());

        return response()->json($user->load(['roles:id,name,label', 'profile']), 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->load([
            'roles:id,name,label',
            'roles.permissions',
            'profile',
            'permissions:id,key',
        ]);

        return response()->json($user->toClientPayload());
    }

    public function history(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $logs = ActivityLog::query()
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->id)
            ->whereIn('action', ['user.created', 'user.updated', 'user.deleted'])
            ->with(['user:id,name', 'user.profile:id,user_id,avatar_path'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $items = $logs
            ->map(static fn (ActivityLog $log) => CrmActivityPresenter::toHistoryItem($log))
            ->values()
            ->all();

        return response()->json(['items' => $items]);
    }

    public function updatePermissions(UserPermissionsUpdateRequest $request, User $user): JsonResponse
    {
        if (! $request->user()->isAdministrator()) {
            return response()->json([
                'message' => 'Only administrators can update user permissions.',
            ], 403);
        }

        $this->authorize('update', $user);

        if ($user->isAdministrator()) {
            return response()->json([
                'message' => 'Administrators Have Full Access; Permissions Are Not Stored Per User.',
            ], 422);
        }

        $editableKeys = User::editableCrmPermissionKeys();
        $keys = User::normalizeCrmPermissionKeys(
            $request->validated('permission_keys'),
            $editableKeys
        );

        Permission::ensureRowsForKeys(array_values(array_unique(array_merge(
            $editableKeys,
            $keys,
        ))));

        try {
            $whitelistIds = Permission::idsForKeys($editableKeys);
            $desiredIds = Permission::idsForKeys($keys);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => 'Permission definitions are missing from the database. Run: php artisan db:seed --class=RolePermissionSeeder',
            ], 422);
        }

        $user->loadMissing('permissions');

        $whitelistIdSet = [];
        foreach ($whitelistIds as $wid) {
            $whitelistIdSet[(int) $wid] = true;
        }

        $currentIds = $user->permissions->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $keepIds = array_values(array_filter(
            $currentIds,
            static fn (int $id): bool => ! isset($whitelistIdSet[$id]),
        ));

        $newPivotIds = array_values(array_unique(array_merge($keepIds, array_map('intval', $desiredIds))));

        $user->permissions()->sync($newPivotIds);

        $user->load([
            'roles:id,name,label',
            'roles.permissions',
            'profile',
            'permissions:id,key',
        ]);

        return response()->json($user->toClientPayload());
    }

    public function permissionsMeta(Request $request): JsonResponse
    {
        if (! $request->user()->isAdministrator()) {
            return response()->json(['message' => 'Only administrators can view permission metadata.'], 403);
        }

        // Ensure core page permissions exist so the UI always renders Staff/Webmaster/Clients rows.
        User::editableCrmPermissionKeys();

        $permissions = Permission::query()
            ->select(['key', 'label', 'module'])
            ->where(function (Builder $q) {
                $q->where('key', 'like', '%.view')
                    ->orWhere('key', 'like', '%.create')
                    ->orWhere('key', 'like', '%.update')
                    ->orWhere('key', 'like', '%.delete');
            })
            ->orderBy('module')
            ->orderBy('key')
            ->get()
            ->map(static fn (Permission $p) => [
                'key' => (string) $p->key,
                'label' => (string) $p->label,
                'module' => (string) $p->module,
            ])
            ->values();

        return response()->json([
            'items' => $permissions,
        ]);
    }

    public function update(UserUpdateRequest $request, User $user): JsonResponse
    {
        $updated = $this->userService->update($user, $request->validated(), $request->user());

        return response()->json($updated->load(['roles:id,name,label', 'profile']));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->userService->delete($user, $request->user());

        return response()->json(['message' => 'User deleted.']);
    }

    public function uploadAvatar(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $request->validate([
            'avatar' => ['required', 'file', 'max:4096', 'mimes:jpeg,jpg,png,webp'],
        ]);

        try {
            $this->avatars->replaceForUser($user, $request->file('avatar'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json($user->fresh()->load(['roles:id,name,label', 'profile']));
    }

    public function destroyAvatar(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $this->avatars->deleteForUser($user);

        return response()->json($user->fresh()->load(['roles:id,name,label', 'profile']));
    }
}
