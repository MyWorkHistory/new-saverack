<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /** Permission keys editable from CRM “User Permissions” (direct user grants). */
    public const CRM_MODULE_PERMISSION_KEYS = [
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'webmaster.view',
        'webmaster.create',
        'webmaster.update',
        'webmaster.delete',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'last_login_at',
        'last_login_ip',
        'legacy_user_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    /** Direct permission grants (merged with role permissions for effective access). */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user')->withTimestamps();
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function createdWebmasterTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function assignedWebmasterTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    /**
     * Full CRM user management (see UserPolicy). SaveRack uses roles named `admin` and `staff`;
     * anyone with the `admin` role qualifies, including accounts that also have `staff`.
     */
    public function isAdministrator(): bool
    {
        $this->loadMissing('roles');
        $adminNames = ['admin', 'administrator', 'super_admin', 'superadmin'];

        foreach ($this->roles as $role) {
            $n = strtolower((string) $role->name);
            if (in_array($n, $adminNames, true)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(string $name): bool
    {
        $this->loadMissing('roles');
        $needle = strtolower($name);

        if ($needle === 'admin' && $this->isAdministrator()) {
            return true;
        }

        foreach ($this->roles as $role) {
            if (strtolower((string) $role->name) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function hasPermission(string $key): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        $this->loadMissing('roles.permissions', 'permissions');

        foreach ($this->permissions as $permission) {
            if ($permission->key === $key) {
                return true;
            }
        }

        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                if ($permission->key === $key) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function allPermissionKeys(): array
    {
        $this->loadMissing('roles.permissions', 'permissions');

        $fromRoles = $this->roles
            ->flatMap(function (Role $role) {
                return $role->permissions->pluck('key');
            })
            ->all();

        $fromDirect = $this->permissions->pluck('key')->all();

        return array_values(array_unique(array_merge($fromRoles, $fromDirect)));
    }

    /**
     * CRM owner (Audi Kowalski) — tickets module MVP.
     */
    public function isCrmOwner(): bool
    {
        $owner = config('crm.owner_email');
        if ($owner === null || $owner === '') {
            return false;
        }

        return strcasecmp((string) $this->email, (string) $owner) === 0;
    }

    /**
     * Direct user→permission grants limited to CRM module keys (Staff / Webmaster matrix).
     *
     * @return list<string>
     */
    public function directCrmPermissionKeys(): array
    {
        $this->loadMissing('permissions');
        $allowed = array_flip(self::CRM_MODULE_PERMISSION_KEYS);
        $out = [];
        foreach ($this->permissions as $permission) {
            $k = (string) $permission->key;
            if (isset($allowed[$k])) {
                $out[] = $k;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * API shape for auth and user detail (exposes merged permission_keys, hides raw permissions pivot list).
     *
     * @return array<string, mixed>
     */
    public function toClientPayload(): array
    {
        $this->loadMissing('roles.permissions', 'roles', 'permissions', 'profile');

        $arr = $this->toArray();
        unset($arr['permissions']);

        return array_merge($arr, [
            'permission_keys' => $this->allPermissionKeys(),
            'direct_permission_keys' => $this->directCrmPermissionKeys(),
            'is_admin' => $this->isAdministrator(),
            'is_crm_owner' => $this->isCrmOwner(),
        ]);
    }
}

