<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ClientAccountUserService
{
    public function filteredAccountUsersQuery(array $filters): Builder
    {
        $query = User::query()
            ->whereNotNull('users.client_account_id')
            ->with([
                'clientAccount' => function ($q) {
                    $q->select('client_accounts.id', 'client_accounts.company_name', 'client_accounts.email');
                },
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

        if ($status !== '' && $status !== 'all' && in_array($status, ['pending', 'active', 'inactive'], true)) {
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
        $user->loadMissing('clientAccount:id,company_name,email');
        $account = $user->clientAccount;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
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

    public function createSecondary(ClientAccount $account, array $data): User
    {
        $email = (string) $data['email'];
        if (strcasecmp($email, (string) $account->email) === 0) {
            throw ValidationException::withMessages([
                'email' => ['Use a different email than the account primary login.'],
            ]);
        }

        return DB::transaction(function () use ($account, $data, $email) {
            $user = User::query()->create([
                'name' => trim((string) $data['name']),
                'email' => $email,
                'password' => Hash::make((string) $data['password']),
                'status' => (string) $data['status'],
                'client_account_id' => $account->id,
                'account_user_role' => User::ACCOUNT_USER_ROLE_CUSTOMER_SERVICE,
                'is_account_primary' => false,
            ]);
            $user->roles()->sync([]);

            return $user->fresh(['clientAccount']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAccountUser(User $user, array $data): User
    {
        if ($user->is_account_primary) {
            unset($data['email']);
        }

        if (isset($data['password']) && $data['password'] !== null && $data['password'] !== '') {
            $data['password'] = Hash::make((string) $data['password']);
        } else {
            unset($data['password']);
        }

        unset($data['client_account_id'], $data['account_user_role'], $data['is_account_primary']);

        $user->update($data);

        return $user->fresh(['clientAccount']);
    }

    public function deleteAccountUser(User $user): void
    {
        if ($user->is_account_primary) {
            abort(403, 'The primary account admin cannot be deleted here.');
        }

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete();
        });
    }
}
