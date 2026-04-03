<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserBulkUpdateRequest;
use App\Http\Requests\UserPermissionsUpdateRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\User;
use App\Support\UserStaffHistory;
use App\Services\UserAvatarService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

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
        ]));

        return response()->json($users);
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
            ->whereIn('action', ['user.created', 'user.updated'])
            ->with(['user:id,name'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $items = $logs->map(function (ActivityLog $log) {
            $actorName = ($log->user !== null) ? (string) $log->user->name : null;

            return [
                'id' => $log->id,
                'created_at' => $log->created_at !== null ? $log->created_at->toIso8601String() : null,
                'actor_name' => $actorName !== null ? $actorName : 'System',
                'actor_initials' => UserStaffHistory::initials($actorName),
                'line' => UserStaffHistory::formatLogLine($log),
                'body' => UserStaffHistory::formatLogBody($log),
            ];
        })->values()->all();

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

        $keys = array_values(array_unique($request->validated('permission_keys')));

        Permission::ensureRowsForKeys(array_values(array_unique(array_merge(
            User::CRM_MODULE_PERMISSION_KEYS,
            $keys,
        ))));

        try {
            $whitelistIds = Permission::idsForKeys(User::CRM_MODULE_PERMISSION_KEYS);
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
