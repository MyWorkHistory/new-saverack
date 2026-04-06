<?php

namespace App\Services;

use App\Models\User;
use App\Support\UserStaffHistory;
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
        'job_position',
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
        $plan = isset($filters['plan']) ? trim((string) $filters['plan']) : '';
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 500);
        $sortBy = (string) ($filters['sort_by'] ?? 'id');
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSortColumns = [
            'id', 'name', 'email', 'status', 'created_at', 'updated_at',
            'job_position', 'birthday', 'hire_date', 'role',
        ];
        if (! in_array($sortBy, $allowedSortColumns, true)) {
            $sortBy = 'id';
        }

        $query = User::query()
            ->with([
                'roles' => function ($q) {
                    $q->select('roles.id', 'roles.name', 'roles.label');
                },
                'profile' => function ($q) {
                    $q->select('id', 'user_id', 'job_position', 'birthday', 'hire_date', 'avatar_path');
                },
            ])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($nested) use ($search) {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('profile', function ($p) use ($search) {
                            $p->where('phone', 'like', "%{$search}%")
                                ->orWhere('job_position', 'like', "%{$search}%");
                        });
                });
            })
            ->when($roleId, function ($q) use ($roleId) {
                $q->whereHas('roles', function ($roleQuery) use ($roleId) {
                    $roleQuery->where('roles.id', $roleId);
                });
            })
            ->when($status !== null && $status !== '' && $status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($plan !== '', function ($q) use ($plan) {
                $q->whereHas('profile', function ($p) use ($plan) {
                    $p->where('job_position', 'like', '%'.$plan.'%');
                });
            });

        $profileSortColumns = ['job_position', 'birthday', 'hire_date'];
        if (in_array($sortBy, $profileSortColumns, true)) {
            $query->leftJoin('user_profiles', 'users.id', '=', 'user_profiles.user_id')
                ->select('users.*');
            $profileColumn = [
                'job_position' => 'user_profiles.job_position',
                'birthday' => 'user_profiles.birthday',
                'hire_date' => 'user_profiles.hire_date',
            ][$sortBy];
            $query->orderBy($profileColumn, $sortDir);
        } elseif ($sortBy === 'role') {
            $query->select('users.*');
            $dir = $sortDir === 'desc' ? 'DESC' : 'ASC';
            $query->orderByRaw(
                '(SELECT MIN(COALESCE(roles.label, roles.name)) FROM role_user INNER JOIN roles ON roles.id = role_user.role_id WHERE role_user.user_id = users.id) '.$dir
            );
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        return $query->paginate($perPage);
    }

    public function create(array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $roleIds = array_values(array_unique(array_map('intval', $data['role_ids'] ?? [])));
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
                $this->activityLog->log($actor, 'user.created', $user, null, [
                    'new_user_name' => $user->name,
                ]);
            }

            return $user->refresh()->load('profile');
        });
    }

    public function update(User $user, array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            $user->load(['profile', 'roles']);
            $before = UserStaffHistory::snapshot($user);

            $willChangePassword = isset($data['password'])
                && $data['password'] !== null
                && $data['password'] !== '';

            $roleIds = isset($data['role_ids']) && is_array($data['role_ids'])
                ? array_values(array_unique(array_map('intval', $data['role_ids'])))
                : null;
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

            $user->refresh()->load(['roles', 'profile']);
            $after = UserStaffHistory::snapshot($user);

            if ($actor) {
                if ($willChangePassword) {
                    $this->activityLog->log($actor, 'user.updated', $user, null, [
                        'kind' => 'password',
                        'field' => 'Password',
                    ]);
                }
                foreach (UserStaffHistory::diff($before, $after) as $row) {
                    $this->activityLog->log($actor, 'user.updated', $user, null, $row);
                }
            }

            return $user;
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

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, int>|null  $roleIds  When not null, replaces roles for each user.
     * @return int Number of users updated
     */
    public function bulkUpdateStatusAndRoles(
        array $userIds,
        ?string $status,
        ?array $roleIds,
        ?User $actor = null
    ): int {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        return (int) DB::transaction(function () use ($userIds, $status, $roleIds, $actor) {
            $count = 0;
            foreach ($userIds as $id) {
                $user = User::query()->find($id);
                if (! $user) {
                    continue;
                }
                if ($status !== null && $status !== '') {
                    $user->status = $status;
                    $user->save();
                }
                if ($roleIds !== null) {
                    $user->roles()->sync(array_values(array_unique(array_map('intval', $roleIds))));
                }
                if ($actor) {
                    $this->activityLog->log($actor, 'user.updated', $user, 'Bulk user update', ['email' => $user->email]);
                }
                $count++;
            }

            return $count;
        });
    }
}
