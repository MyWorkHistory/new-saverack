<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
    /** Request keys stored on {@see \App\Models\UserProfile}. */
    private const PROFILE_INPUT_KEYS = [
        'phone',
        'personal_email',
        'birthday',
        'address',
        'city',
        'state',
        'zip',
        'region',
        'employee_type',
        'hire_date',
        'terminate_date',
        'bio',
    ];

    /** @var ActivityLogService */
    protected $activityLog;

    public function __construct(ActivityLogService $activityLog)
    {
        $this->activityLog = $activityLog;
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;
        $roleId = isset($filters['role_id']) ? (int) $filters['role_id'] : null;
        $status = isset($filters['status']) ? (string) $filters['status'] : null;
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
            ->when($roleId, function ($query) use ($roleId) {
                $query->whereHas('roles', function ($q) use ($roleId) {
                    $q->where('roles.id', $roleId);
                });
            })
            ->when($status !== null && $status !== '' && $status !== 'all', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage);
    }

    public function create(array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $roleIds = $data['role_ids'] ?? [];
            unset($data['role_ids']);
            $profileData = $this->extractProfileInput($data);
            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }
            $user = User::create($data);
            $user->roles()->sync($roleIds);
            if ($profileData !== []) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    $profileData
                );
            }
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
            unset($data['role_ids']);
            $profileData = $this->extractProfileInput($data);
            if (empty($data['password'])) {
                unset($data['password']);
            } elseif (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }
            $user->update($data);
            if (is_array($roleIds)) {
                $user->roles()->sync($roleIds);
            }
            if ($profileData !== []) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    $profileData
                );
            }
            if ($actor) {
                $this->activityLog->log($actor, 'user.updated', $user, 'User updated', ['email' => $user->email]);
            }

            return $user->refresh()->load(['roles', 'profile']);
        });
    }

    /**
     * Pull profile columns off $data (validated request body).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractProfileInput(array &$data): array
    {
        $out = [];
        foreach (self::PROFILE_INPUT_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            unset($data[$key]);
            $out[$key] = ($value === '' || $value === null) ? null : $value;
        }

        return $out;
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
