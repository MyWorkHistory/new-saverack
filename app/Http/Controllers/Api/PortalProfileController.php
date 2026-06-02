<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\PortalOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PortalProfileController extends Controller
{
    /** @var PortalOnboardingService */
    protected $onboarding;

    public function __construct(PortalOnboardingService $onboarding)
    {
        $this->onboarding = $onboarding;
    }

    public function show(Request $request): JsonResponse
    {
        [$user, $account] = $this->resolvePortalUserAndAccount($request);
        Gate::forUser($request->user())->authorize('view', $account);

        return response()->json($this->serializeProfile($user, $account));
    }

    public function update(Request $request): JsonResponse
    {
        [$user, $account] = $this->resolvePortalUserAndAccount($request);
        Gate::forUser($request->user())->authorize('view', $account);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'company_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'street' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'state' => ['nullable', 'string', 'max:64'],
            'zip' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:128'],
        ]);

        DB::transaction(function () use ($user, $account, $validated) {
            $this->onboarding->updateAccountProfile($user, $account, $validated);
        });

        $user->refresh();
        $account->refresh();

        return response()->json($this->serializeProfile($user, $account));
    }

    /**
     * @return array{0: User, 1: ClientAccount}
     */
    private function resolvePortalUserAndAccount(Request $request): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $clientAccountId = (int) ($user->client_account_id ?? 0);
        if ($clientAccountId <= 0) {
            throw ValidationException::withMessages([
                'client_account_id' => ['Portal profile is only available for client portal users.'],
            ]);
        }

        $account = ClientAccount::query()->findOrFail($clientAccountId);

        return [$user, $account];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProfile(User $user, ClientAccount $account): array
    {
        return $this->onboarding->serializeProfile($user, $account);
    }
}
