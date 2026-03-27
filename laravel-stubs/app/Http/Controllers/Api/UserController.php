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
    }

    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->paginate($request->only(['search', 'per_page']));
        return response()->json($users);
    }

    public function store(UserStoreRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());
        return response()->json($user->load('role:id,name,label'), 201);
    }

    public function show(int $id): JsonResponse
    {
        $user = User::with('role:id,name,label')->findOrFail($id);
        return response()->json($user);
    }

    public function update(UserUpdateRequest $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $updated = $this->userService->update($user, $request->validated());
        return response()->json($updated->load('role:id,name,label'));
    }

    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $this->userService->delete($user);
        return response()->json(['message' => 'User deleted.']);
    }
}

