<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** @var ActivityLogService */
    protected $activityLog;

    public function __construct(ActivityLogService $activityLog)
    {
        $this->activityLog = $activityLog;
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials provided.']]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages(['email' => ['User account is not active.']]);
        }

        $token = $user->createToken('crm-api-token')->plainTextToken;
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $this->activityLog->log($user, 'auth.login', $user, 'Login', []);

        $user->load(['roles.permissions', 'profile']);

        return response()->json([
            'token' => $token,
            'user' => $this->serializeUserForClient($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles.permissions', 'profile']);

        return response()->json($this->serializeUserForClient($user));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUserForClient(User $user): array
    {
        $permissionKeys = $user->roles
            ->flatMap(function (Role $role) {
                return $role->permissions->pluck('key');
            })
            ->unique()
            ->values()
            ->all();

        $user->loadMissing('roles');

        return array_merge($user->toArray(), [
            'permission_keys' => $permissionKeys,
            'is_admin' => $user->isAdministrator(),
            'is_crm_owner' => $user->isCrmOwner(),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'If the email exists, reset instructions are sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $status = Password::reset(
            $validated,
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => 'Password reset successful.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $token = $user->currentAccessToken();
            if ($token) {
                $token->delete();
            }
        }

        return response()->json(['message' => 'Logged out.']);
    }
}
