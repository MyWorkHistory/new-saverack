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
     * One DB row per key, same order as $keys (deduped). Avoids whereIn binding issues and OR-clause edge cases.
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

        $ids = [];
        foreach ($keys as $key) {
            $id = static::query()->where('key', $key)->value('id');
            if ($id === null) {
                static::ensureRowsForKeys([$key]);
                $id = static::query()->where('key', $key)->value('id');
            }
            if ($id === null) {
                $label = is_string($key) ? $key : json_encode($key);
                throw new \RuntimeException('Missing permission row for key: '.$label);
            }
            $ids[] = (int) $id;
        }

        return $ids;
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
            'clients.view' => ['label' => 'View client accounts', 'module' => 'clients'],
            'clients.create' => ['label' => 'Create client accounts', 'module' => 'clients'],
            'clients.update' => ['label' => 'Update client accounts', 'module' => 'clients'],
            'clients.delete' => ['label' => 'Delete client accounts', 'module' => 'clients'],
            'client_users.view' => ['label' => 'View client portal users', 'module' => 'client_users'],
            'client_users.create' => ['label' => 'Create client portal users', 'module' => 'client_users'],
            'client_users.update' => ['label' => 'Update client portal users', 'module' => 'client_users'],
            'client_users.delete' => ['label' => 'Delete client portal users', 'module' => 'client_users'],
            'billing.view' => ['label' => 'View billing', 'module' => 'billing'],
            'billing.create' => ['label' => 'Create invoices', 'module' => 'billing'],
            'billing.update' => ['label' => 'Update invoices', 'module' => 'billing'],
            'billing.delete' => ['label' => 'Delete draft invoices', 'module' => 'billing'],
        ];

        foreach (array_unique($keys) as $key) {
            if (! is_string($key)) {
                continue;
            }
            $key = trim($key);
            if ($key === '') {
                continue;
            }

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
