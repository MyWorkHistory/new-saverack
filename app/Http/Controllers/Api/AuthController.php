<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicClientRegisterRequest;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\PortalClientProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** @var ActivityLogService */
    protected $activityLog;

    /** @var PortalClientProvisioningService */
    protected $portalClients;

    public function __construct(ActivityLogService $activityLog, PortalClientProvisioningService $portalClients)
    {
        $this->activityLog = $activityLog;
        $this->portalClients = $portalClients;
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
            $pendingPortal = $user->status === 'pending'
                && $user->client_account_id !== null;
            if (! $pendingPortal) {
                throw ValidationException::withMessages(['email' => ['User account is not active.']]);
            }
        }

        $token = $user->createToken('crm-api-token')->plainTextToken;
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $this->activityLog->log($user, 'auth.login', $user, 'Login', []);

        $user->load(['roles.permissions', 'profile', 'permissions']);

        return response()->json([
            'token' => $token,
            'user' => $user->toClientPayload(),
        ]);
    }

    public function register(PublicClientRegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->portalClients->registerNew3plClient(
            $validated['company_name'],
            $validated['full_name'],
            $validated['email'],
            $validated['phone'],
            $validated['password'],
        );

        $token = $user->createToken('crm-api-token')->plainTextToken;
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $this->activityLog->log($user, 'auth.register', $user, '3PL registration', []);

        $user->load(['roles.permissions', 'profile', 'permissions']);

        return response()->json([
            'token' => $token,
            'user' => $user->toClientPayload(),
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles.permissions', 'profile', 'permissions']);

        return response()->json($user->toClientPayload());
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
