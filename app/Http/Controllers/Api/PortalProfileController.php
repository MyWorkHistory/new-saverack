<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PortalProfileController extends Controller
{
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

        $nameParts = preg_split('/\s+/', trim((string) $validated['name']), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = isset($nameParts[1]) ? trim($nameParts[1]) : '';

        DB::transaction(function () use ($user, $account, $validated, $firstName, $lastName) {
            $user->name = trim((string) $validated['name']);
            $user->email = trim((string) $validated['email']);
            $user->save();

            $account->company_name = trim((string) $validated['company_name']);
            $account->contact_first_name = $firstName;
            $account->contact_last_name = $lastName;
            $account->email = trim((string) $validated['email']);
            $account->phone = $this->nullableTrim($validated['phone'] ?? null);
            $account->street = $this->nullableTrim($validated['street'] ?? null);
            $account->city = $this->nullableTrim($validated['city'] ?? null);
            $account->state = $this->nullableTrim($validated['state'] ?? null);
            $account->zip = $this->nullableTrim($validated['zip'] ?? null);
            $account->country = $this->nullableTrim($validated['country'] ?? null);
            $account->save();
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
        $contactName = trim(implode(' ', array_filter([
            trim((string) $account->contact_first_name),
            trim((string) $account->contact_last_name),
        ])));

        return [
            'user_id' => $user->id,
            'client_account_id' => $account->id,
            'name' => $user->name,
            'email' => $user->email,
            'company_name' => $account->company_name,
            'contact_full_name' => $contactName !== '' ? $contactName : $user->name,
            'phone' => $account->phone,
            'street' => $account->street,
            'city' => $account->city,
            'state' => $account->state,
            'zip' => $account->zip,
            'country' => $account->country,
        ];
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $t = trim($value);

        return $t === '' ? null : $t;
    }
}
