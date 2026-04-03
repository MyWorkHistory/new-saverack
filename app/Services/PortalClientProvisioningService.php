<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class PortalClientProvisioningService
{
    /**
     * @return array{0: string, 1: string|null}
     */
    public function splitFullName(string $fullName): array
    {
        $t = trim($fullName);
        if ($t === '') {
            return ['', null];
        }
        $parts = preg_split('/\s+/', $t, 2, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return [$t, null];
        }

        $first = $parts[0];
        $last = isset($parts[1]) ? $parts[1] : null;

        return [$first, $last];
    }

    /**
     * Self-serve: pending client account + pending portal user.
     */
    public function registerNew3plClient(
        string $companyName,
        string $fullName,
        string $email,
        string $phone,
        string $plainPassword
    ): User {
        [$first, $last] = $this->splitFullName($fullName);

        return DB::transaction(function () use ($companyName, $fullName, $email, $phone, $plainPassword, $first, $last) {
            $account = ClientAccount::query()->create([
                'status' => ClientAccount::STATUS_PENDING,
                'company_name' => $companyName,
                'contact_first_name' => $first !== '' ? $first : null,
                'contact_last_name' => $last,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'notify_email' => true,
            ]);

            return $this->createPortalUserForAccount($account, $fullName, $email, $phone, $plainPassword);
        });
    }

    /**
     * CRM: account row exists; add portal user (same email as account).
     */
    public function attachPortalLoginToAccount(
        ClientAccount $account,
        string $fullName,
        string $plainPassword
    ): User {
        if ($account->portalUser !== null) {
            throw new RuntimeException('This client account already has a portal login.');
        }

        if (User::query()->where('email', $account->email)->exists()) {
            throw new RuntimeException('A user with this email already exists.');
        }

        [$first, $last] = $this->splitFullName($fullName);

        return DB::transaction(function () use ($account, $fullName, $plainPassword, $first, $last) {
            $account->update([
                'contact_first_name' => $first !== '' ? $first : $account->contact_first_name,
                'contact_last_name' => $last !== null ? $last : $account->contact_last_name,
            ]);
            $account->refresh();

            return $this->createPortalUserForAccount(
                $account,
                $fullName,
                (string) $account->email,
                (string) ($account->phone ?? ''),
                $plainPassword
            );
        });
    }

    private function createPortalUserForAccount(
        ClientAccount $account,
        string $fullName,
        string $email,
        string $phone,
        string $plainPassword
    ): User {
        $user = User::query()->create([
            'name' => trim($fullName),
            'email' => $email,
            'password' => Hash::make($plainPassword),
            'status' => 'pending',
            'client_account_id' => $account->id,
        ]);

        UserProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['phone' => $phone !== '' ? $phone : null]
        );

        $role = Role::query()->where('name', 'client')->first();
        if ($role === null) {
            throw new RuntimeException('Client role is missing. Run database seeders.');
        }
        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions', 'profile', 'permissions']);
    }
}
