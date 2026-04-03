<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = [
        'key',
        'label',
        'module',
    ];

    /**
     * Create missing permission rows so pivot sync/attach can resolve IDs (mirrors RolePermissionSeeder).
     *
     * @param  list<string>  $keys
     */
    /**
     * Resolve permission IDs without whereIn (some PDO/MySQL setups error with IN () / binding edge cases).
     *
     * @param  list<string>  $keys
     * @return list<int>
     */
    public static function idsForKeys(array $keys): array
    {
        $keys = array_values(array_unique(array_filter(
            $keys,
            static fn ($k): bool => is_string($k) && $k !== '',
        )));

        if ($keys === []) {
            return [];
        }

        return static::query()
            ->where(function ($q) use ($keys): void {
                $q->where('key', $keys[0]);
                for ($i = 1, $n = count($keys); $i < $n; $i++) {
                    $q->orWhere('key', $keys[$i]);
                }
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    public static function ensureRowsForKeys(array $keys): void
    {
        $metaByKey = [
            'users.view' => ['label' => 'View users', 'module' => 'users'],
            'users.create' => ['label' => 'Create users', 'module' => 'users'],
            'users.update' => ['label' => 'Update users', 'module' => 'users'],
            'users.delete' => ['label' => 'Delete users', 'module' => 'users'],
            'webmaster.view' => ['label' => 'View webmaster tasks', 'module' => 'webmaster'],
            'webmaster.create' => ['label' => 'Create webmaster tasks', 'module' => 'webmaster'],
            'webmaster.update' => ['label' => 'Update webmaster tasks', 'module' => 'webmaster'],
            'webmaster.delete' => ['label' => 'Delete webmaster tasks', 'module' => 'webmaster'],
        ];

        foreach (array_unique($keys) as $key) {
            $meta = $metaByKey[$key] ?? [
                'label' => $key,
                'module' => (str_contains($key, '.')) ? strstr($key, '.', true) : 'crm',
            ];

            static::query()->firstOrCreate(['key' => $key], $meta);
        }
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role')->withPivot('assigned_at');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'permission_user')->withTimestamps();
    }
}
