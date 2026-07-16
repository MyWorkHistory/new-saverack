<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use App\Models\UserNote;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ClientAccountUserService
{
    /** @var ActivityLogService */
    protected $activityLog;

    /** @var PortalClientProvisioningService */
    protected $portalProvisioning;

    public function __construct(
        ActivityLogService $activityLog,
        PortalClientProvisioningService $portalProvisioning
    ) {
        $this->activityLog = $activityLog;
        $this->portalProvisioning = $portalProvisioning;
    }

    public function filteredAccountUsersQuery(array $filters): Builder
    {
        $query = User::query()
            ->whereNotNull('users.client_account_id')
            ->with([
                'clientAccount' => function ($q) {
                    $q->select('client_accounts.id', 'client_accounts.company_name', 'client_accounts.email');
                },
                'profile:id,user_id,avatar_path,phone',
            ]);

        $this->applyAccountUserDirectoryFilters($query, $filters);

        return $query;
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 500);
        $sortBy = (string) ($filters['sort_by'] ?? 'id');
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['id', 'name', 'email', 'status', 'created_at', 'company_name', 'account_user_role'];
        if (! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'id';
        }

        $query = $this->filteredAccountUsersQuery($filters);

        if ($sortBy === 'company_name') {
            $query->leftJoin('client_accounts', 'client_accounts.id', '=', 'users.client_account_id')
                ->select('users.*')
                ->orderBy('client_accounts.company_name', $sortDir);
        } else {
            $query->orderBy('users.'.$sortBy, $sortDir);
        }

        return $query->paginate($perPage);
    }

    private function applyAccountUserDirectoryFilters(Builder $query, array $filters): void
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $status = isset($filters['status']) ? trim((string) $filters['status']) : '';
        $clientAccountId = isset($filters['client_account_id']) && $filters['client_account_id'] !== '' && $filters['client_account_id'] !== 'all'
            ? (int) $filters['client_account_id']
            : null;

        if ($clientAccountId !== null && $clientAccountId > 0) {
            $query->where('users.client_account_id', $clientAccountId);
        }

        if ($status !== '' && $status !== 'all' && in_array($status, ['active', 'inactive'], true)) {
            $query->where('users.status', $status);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('users.name', 'like', $like)
                    ->orWhere('users.email', 'like', $like)
                    ->orWhereHas('clientAccount', function ($c) use ($like) {
                        $c->where('company_name', 'like', $like);
                    });
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(User $user): array
    {
        $user->loadMissing([
            'clientAccount:id,company_name,email',
            'profile:id,user_id,avatar_path,phone',
        ]);
        $account = $user->clientAccount;
        $profile = $user->profile;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $profile !== null ? $profile->phone : null,
            'avatar_url' => $profile !== null ? $profile->avatar_url : null,
            'status' => $user->status,
            'client_account_id' => $user->client_account_id,
            'company_name' => $account !== null ? $account->company_name : null,
            'account_email' => $account !== null ? $account->email : null,
            'account_user_role' => $user->account_user_role,
            'account_user_role_label' => $this->roleLabel($user->account_user_role),
            'is_account_primary' => (bool) $user->is_account_primary,
            'created_at' => $user->created_at !== null
                ? $user->created_at->toIso8601String()
                : null,
            'updated_at' => $user->updated_at !== null
                ? $user->updated_at->toIso8601String()
                : null,
        ];
    }

    private function roleLabel(?string $role): ?string
    {
        if ($role === User::ACCOUNT_USER_ROLE_ADMIN) {
            return 'Admin';
        }
        if ($role === User::ACCOUNT_USER_ROLE_CUSTOMER_SERVICE) {
            return 'Customer Service';
        }

        return null;
    }

    /**
     * Create a portal user for an account: primary admin (account email) or secondary user.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForAccount(ClientAccount $account, array $data, ?User $actor = null): User
    {
        $email = trim((string) ($data['email'] ?? ''));
        $accountEmail = trim((string) $account->email);

        if ($accountEmail !== '' && strcasecmp($email, $accountEmail) === 0) {
            if ($account->primaryAccountUser()->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['A user with this email already exists for this account.'],
                ]);
            }

            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                $name = $account->contactFullName();
            }
            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => ['Name is required to create the primary portal login.'],
                ]);
            }

            $user = $this->portalProvisioning->attachPortalLoginToAccount(
                $account,
                $name,
                (string) $data['password']
            );
            $status = (string) ($data['status'] ?? 'active');
            if (in_array($status, ['active', 'inactive'], true) && $user->status !== $status) {
                $user->update(['status' => $status]);
                $user = $user->fresh(['clientAccount']);
            }
            $this->syncPhone($user, $data['phone'] ?? null);
            if ($actor !== null) {
                $this->activityLog->log($actor, 'portal_user.created', $user, null, [
                    'email' => (string) $user->email,
                ]);
            }

            return $user->fresh(['clientAccount', 'profile']);
        }

        return $this->createSecondary($account, $data, $actor);
    }

    public function createSecondary(ClientAccount $account, array $data, ?User $actor = null): User
    {
        $email = (string) $data['email'];

        return DB::transaction(function () use ($account, $data, $email, $actor) {
            $status = (string) ($data['status'] ?? 'active');
            if (! in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }
            $role = (string) ($data['account_user_role'] ?? User::ACCOUNT_USER_ROLE_CUSTOMER_SERVICE);
            if (! in_array($role, [User::ACCOUNT_USER_ROLE_ADMIN, User::ACCOUNT_USER_ROLE_CUSTOMER_SERVICE], true)) {
                $role = User::ACCOUNT_USER_ROLE_CUSTOMER_SERVICE;
            }
            $user = User::query()->create([
                'name' => trim((string) $data['name']),
                'email' => $email,
                'password' => Hash::make((string) $data['password']),
                'status' => $status,
                'client_account_id' => $account->id,
                'account_user_role' => $role,
                'is_account_primary' => false,
            ]);
            $user->roles()->sync([]);
            $this->syncPhone($user, $data['phone'] ?? null);

            $fresh = $user->fresh(['clientAccount', 'profile']);
            if ($actor !== null) {
                $this->activityLog->log($actor, 'portal_user.created', $fresh, null, [
                    'email' => (string) $fresh->email,
                ]);
            }

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAccountUser(User $user, array $data, ?User $actor = null): User
    {
        if ($user->is_account_primary) {
            unset($data['email'], $data['account_user_role']);
        }

        $phone = array_key_exists('phone', $data) ? $data['phone'] : null;
        $hasPhone = array_key_exists('phone', $data);
        unset($data['phone']);

        if (isset($data['password']) && $data['password'] !== null && $data['password'] !== '') {
            $data['password'] = Hash::make((string) $data['password']);
        } else {
            unset($data['password']);
        }

        unset($data['client_account_id'], $data['is_account_primary']);

        if (isset($data['account_user_role'])
            && ! in_array((string) $data['account_user_role'], [
                User::ACCOUNT_USER_ROLE_ADMIN,
                User::ACCOUNT_USER_ROLE_CUSTOMER_SERVICE,
            ], true)) {
            unset($data['account_user_role']);
        }

        if (isset($data['status']) && ! in_array((string) $data['status'], ['active', 'inactive'], true)) {
            unset($data['status']);
        }

        $changedKeys = array_keys($data);
        if ($data !== []) {
            $user->update($data);
        }
        if ($hasPhone) {
            $this->syncPhone($user, $phone);
            $changedKeys[] = 'phone';
        }

        $fresh = $user->fresh(['clientAccount', 'profile']);
        if ($actor !== null && $changedKeys !== []) {
            $this->activityLog->log($actor, 'portal_user.updated', $fresh, null, [
                'email' => (string) $fresh->email,
                'fields' => array_values(array_unique($changedKeys)),
            ]);
        }

        return $fresh;
    }

    /**
     * Transfer primary admin flag to another portal user on this account.
     */
    public function makePrimary(ClientAccount $account, User $user, ?User $actor = null): User
    {
        if ($user->client_account_id === null
            || (int) $user->client_account_id !== (int) $account->id) {
            abort(404);
        }

        if ($user->is_account_primary) {
            return $user->fresh(['clientAccount', 'profile']);
        }

        if ((string) $user->status !== 'active') {
            throw ValidationException::withMessages([
                'user' => ['Only an active user can be made the primary admin.'],
            ]);
        }

        return DB::transaction(function () use ($account, $user, $actor) {
            User::query()
                ->where('client_account_id', $account->id)
                ->where('is_account_primary', true)
                ->update(['is_account_primary' => false]);

            $user->is_account_primary = true;
            $user->account_user_role = User::ACCOUNT_USER_ROLE_ADMIN;
            $user->save();

            $email = trim((string) $user->email);
            if ($email !== '') {
                $account->email = $email;
                $account->save();
            }

            $fresh = $user->fresh(['clientAccount', 'profile']);
            if ($actor !== null) {
                $this->activityLog->log($actor, 'portal_user.updated', $fresh, null, [
                    'email' => (string) $fresh->email,
                    'fields' => ['is_account_primary', 'account_user_role'],
                    'made_primary' => true,
                ]);
            }

            return $fresh;
        });
    }

    public function deleteAccountUser(User $user, ?User $actor = null): void
    {
        if ($user->is_account_primary) {
            abort(403, 'The primary account admin cannot be deleted here.');
        }

        $email = (string) $user->email;

        DB::transaction(function () use ($user, $actor, $email) {
            if ($actor !== null) {
                $this->activityLog->log($actor, 'portal_user.deleted', $user, null, ['email' => $email]);
            }
            $user->tokens()->delete();
            $user->delete();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function notesForUser(User $user): array
    {
        return UserNote::query()
            ->where('user_id', $user->id)
            ->with('author:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(function (UserNote $note) {
                return $this->noteToArray($note);
            })
            ->values()
            ->all();
    }

    public function addNote(User $user, string $body, ?User $actor = null): UserNote
    {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => ['Note cannot be empty.'],
            ]);
        }

        return UserNote::query()->create([
            'user_id' => $user->id,
            'author_id' => $actor ? $actor->id : null,
            'body' => $body,
        ])->fresh('author');
    }

    public function deleteNote(UserNote $note): void
    {
        $note->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function noteToArray(UserNote $note): array
    {
        $note->loadMissing('author:id,name');

        return [
            'id' => $note->id,
            'body' => $note->body,
            'author_id' => $note->author_id,
            'author_name' => $note->author ? $note->author->name : 'Staff',
            'created_at' => $note->created_at ? $note->created_at->toIso8601String() : null,
            'updated_at' => $note->updated_at ? $note->updated_at->toIso8601String() : null,
        ];
    }

    private function syncPhone(User $user, $phone): void
    {
        $value = $phone === null ? null : trim((string) $phone);
        if ($value === '') {
            $value = null;
        }
        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            ['phone' => $value]
        );
    }
}
