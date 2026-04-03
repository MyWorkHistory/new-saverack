<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ClientAccountService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $managerId = isset($filters['account_manager_id']) && $filters['account_manager_id'] !== '' && $filters['account_manager_id'] !== 'all'
            ? (int) $filters['account_manager_id']
            : null;
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 500);
        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSort = [
            'id', 'company_name', 'email', 'status', 'created_at', 'updated_at',
        ];
        if (! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'created_at';
        }

        $query = ClientAccount::query()
            ->with([
                'accountManager' => function ($q) {
                    $q->select('users.id', 'users.name', 'users.email');
                },
            ])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($nested) use ($like) {
                    $nested->where('company_name', 'like', $like)
                        ->orWhere('brand_name', 'like', $like)
                        ->orWhere('website', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('contact_first_name', 'like', $like)
                        ->orWhere('contact_last_name', 'like', $like);
                });
            })
            ->when($managerId !== null && $managerId > 0, fn ($q) => $q->where('account_manager_id', $managerId))
            ->orderBy($sortBy, $sortDir);

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ClientAccount
    {
        $data['status'] = $data['status'] ?? ClientAccount::STATUS_PENDING;

        return ClientAccount::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ClientAccount $account, array $data): ClientAccount
    {
        $account->update($data);

        return $account->fresh(['accountManager']);
    }

    /**
     * @param  list<int>  $ids
     */
    public function bulkUpdateStatus(array $ids, string $status): int
    {
        return ClientAccount::query()->whereIn('id', $ids)->update(['status' => $status]);
    }

    public function delete(ClientAccount $account): void
    {
        $account->delete();
    }

    /**
     * Users that may be assigned as account managers — same population as the Staff directory
     * (administrator + staff CRM roles, so admins appear and validation stays aligned).
     *
     * @return \Illuminate\Support\Collection<int, array{id:int,name:string,email:string}>
     */
    public function accountManagersForMeta()
    {
        $internalRoleNames = ['admin', 'staff'];

        return User::query()
            ->select('users.id', 'users.name', 'users.email')
            ->whereHas('roles', function ($q) use ($internalRoleNames) {
                $q->whereIn('name', $internalRoleNames);
            })
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(ClientAccount $account): array
    {
        $account->loadMissing('accountManager:id,name,email');
        $manager = $account->accountManager;

        return [
            'id' => $account->id,
            'status' => $account->status,
            'company_name' => $account->company_name,
            'brand_name' => $account->brand_name,
            'website' => $account->website,
            'contact_first_name' => $account->contact_first_name,
            'contact_last_name' => $account->contact_last_name,
            'contact_full_name' => $account->contactFullName(),
            'email' => $account->email,
            'phone' => $account->phone,
            'notify_email' => (bool) $account->notify_email,
            'telegram_handle' => $account->telegram_handle,
            'whatsapp_e164' => $account->whatsapp_e164,
            'street' => $account->street,
            'city' => $account->city,
            'state' => $account->state,
            'zip' => $account->zip,
            'country' => $account->country,
            'account_manager_id' => $account->account_manager_id,
            'account_manager' => $manager !== null
                ? ['id' => $manager->id, 'name' => $manager->name, 'email' => $manager->email]
                : null,
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}
