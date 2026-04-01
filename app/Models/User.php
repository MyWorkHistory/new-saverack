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
     * System administrators (full CRM user management). Matches common role names;
     * users may have both this and "staff" without losing admin rights.
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
        $this->loadMissing('roles.permissions');

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
}

