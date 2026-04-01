<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserBulkUpdateRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\User;
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
        return response()->json($user->load(['roles:id,name,label', 'profile']));
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
