<?php

namespace App\Services;

use App\Mail\NewAccountWelcomeMailable;
use App\Mail\NewPortalRegistrationStaffMailable;
use App\Models\ClientAccount;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PortalClientProvisioningService
{
    /** @var ShipHeroCustomerAccountService */
    protected $shipheroCustomers;

    public function __construct(ShipHeroCustomerAccountService $shipheroCustomers)
    {
        $this->shipheroCustomers = $shipheroCustomers;
    }

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
        $email = strtolower(trim($email));
        $companyName = trim($companyName);
        $fullName = trim($fullName);
        $phone = trim($phone);

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This email is already registered. Sign in instead.'],
            ]);
        }

        $existingAccount = ClientAccount::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
        if ($existingAccount !== null) {
            if ($existingAccount->primaryAccountUser()->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['This email is already registered. Sign in instead.'],
                ]);
            }

            return DB::transaction(function () use ($existingAccount, $fullName, $phone, $plainPassword) {
                $user = $this->attachPortalLoginToAccount($existingAccount, $fullName, $plainPassword);
                if ($phone !== '') {
                    UserProfile::query()->updateOrCreate(
                        ['user_id' => $user->id],
                        ['phone' => $phone]
                    );
                    $user->load('profile');
                }

                return $user->fresh(['roles.permissions', 'profile', 'permissions', 'clientAccount']);
            });
        }

        [$first, $last] = $this->splitFullName($fullName);

        return DB::transaction(function () use ($companyName, $fullName, $email, $phone, $plainPassword, $first, $last) {
            $account = ClientAccount::query()->create([
                'status' => ClientAccount::STATUS_PENDING,
                'company_name' => $companyName,
                'contact_first_name' => $first !== '' ? $first : null,
                'contact_last_name' => $last,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'notify_email' => false,
            ]);

            $shipheroId = $this->shipheroCustomers->tryCreateCustomerAccount(
                $companyName,
                $fullName,
                $email,
                $phone
            );
            if (is_string($shipheroId) && trim($shipheroId) !== '') {
                $account->update(['shiphero_customer_account_id' => trim($shipheroId)]);
                $account->refresh();
            }

            $user = $this->createPortalUserForAccount($account, $fullName, $email, $phone, $plainPassword);

            $this->notifyStaffOfRegistration($account, $user);

            return $user;
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
        if ($account->primaryAccountUser()->exists()) {
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
            'account_user_role' => User::ACCOUNT_USER_ROLE_ADMIN,
            'is_account_primary' => true,
        ]);

        UserProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['phone' => $phone !== '' ? $phone : null]
        );

        $user->roles()->sync([]);

        $user = $user->fresh(['roles.permissions', 'profile', 'permissions', 'clientAccount']);

        $this->notifyNewAccountWelcome($account, $user);

        return $user;
    }

    private function notifyNewAccountWelcome(ClientAccount $account, User $user): void
    {
        $to = trim((string) $user->email);
        if ($to === '') {
            return;
        }

        try {
            Mail::to($to)->send(new NewAccountWelcomeMailable($account, $user));
        } catch (\Throwable $e) {
            Log::warning('portal.registration.welcome_email_failed', [
                'client_account_id' => $account->id,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyStaffOfRegistration(ClientAccount $account, User $user): void
    {
        $notify = config('crm.registration_notify_email');
        if (! is_string($notify) || trim($notify) === '') {
            return;
        }

        try {
            Mail::to(trim($notify))->send(new NewPortalRegistrationStaffMailable($account, $user));
        } catch (\Throwable $e) {
            Log::warning('portal.registration.staff_email_failed', [
                'client_account_id' => $account->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
