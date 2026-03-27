<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly UserService $userService)
    {
        $this->authorizeResource(User::class, 'user');
    }

    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->paginate($request->only(['search', 'per_page', 'sort_by', 'sort_dir']));

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
}
