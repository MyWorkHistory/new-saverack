<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UserService
{
    public function __construct(private readonly ActivityLogService $activityLog)
    {
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;
        $perPage = min(max((int) ($filters['per_page'] ?? 10), 5), 100);
        $sortBy = (string) ($filters['sort_by'] ?? 'id');
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSortColumns = ['id', 'name', 'email', 'status', 'created_at', 'updated_at'];
        if (! in_array($sortBy, $allowedSortColumns, true)) {
            $sortBy = 'id';
        }

        return User::query()
            ->with(['roles' => function ($q) {
                $q->select('roles.id', 'roles.name', 'roles.label');
            }])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('profile', function ($p) use ($search) {
                            $p->where('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage);
    }

    public function create(array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $roleIds = $data['role_ids'] ?? [];
            $phone = $data['phone'] ?? null;
            unset($data['role_ids'], $data['phone']);
            $user = User::create($data);
            $user->roles()->sync($roleIds);
            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                array_filter(['phone' => $phone], fn ($v) => $v !== null && $v !== '')
            );
            $user->load('roles');
            if ($actor) {
                $this->activityLog->log($actor, 'user.created', $user, 'User created', ['email' => $user->email]);
            }

            return $user->refresh()->load('profile');
        });
    }

    public function update(User $user, array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            $roleIds = $data['role_ids'] ?? null;
            $phone = $data['phone'] ?? null;
            unset($data['role_ids'], $data['phone']);
            if (empty($data['password'])) {
                unset($data['password']);
            }
            $user->update($data);
            if (is_array($roleIds)) {
                $user->roles()->sync($roleIds);
            }
            if ($phone !== null) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    ['phone' => $phone]
                );
            }
            if ($actor) {
                $this->activityLog->log($actor, 'user.updated', $user, 'User updated', ['email' => $user->email]);
            }

            return $user->refresh()->load(['roles', 'profile']);
        });
    }

    public function delete(User $user, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $actor) {
            if ($actor) {
                $this->activityLog->log($actor, 'user.deleted', $user, 'User deleted', ['email' => $user->email]);
            }
            $user->tokens()->delete();
            $user->delete();
        });
    }
}
