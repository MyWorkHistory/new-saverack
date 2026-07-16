<?php

namespace App\Models;

use App\Support\CrmStaffPermissionCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ACCOUNT_USER_ROLE_ADMIN = 'admin';

    public const ACCOUNT_USER_ROLE_CUSTOMER_SERVICE = 'customer_service';

    private const EDITABLE_PERMISSION_ACTIONS = ['view', 'create', 'update', 'delete'];
    /** CRM modules gated to administrators only — not assignable to staff. */
    private const ADMIN_ONLY_CRM_MODULES = ['users', 'webmaster', 'settings'];

    /** @deprecated Prefer CrmStaffPermissionCatalog::matrixEditableKeys(); kept for ensureRows bootstrap. */
    private const DEFAULT_EDITABLE_PERMISSION_KEYS = [];

    /**
     * Flatten mixed request shapes and keep only allowed CRM permission key strings.
     *
     * @param  mixed  $input
     * @return list<string>
     */
    public static function normalizeCrmPermissionKeys($input, ?array $allowedKeys = null): array
    {
        if (! is_array($input)) {
            return [];
        }

        $allowed = array_flip($allowedKeys ?? self::editableCrmPermissionKeys());
        $flat = [];
        $queue = array_values($input);

        while ($queue !== []) {
            $item = array_pop($queue);
            if (is_string($item)) {
                $flat[] = trim($item);
            } elseif (is_array($item)) {
                foreach ($item as $sub) {
                    $queue[] = $sub;
                }
            } elseif (is_scalar($item)) {
                $flat[] = trim((string) $item);
            }
        }

        $out = [];
        foreach ($flat as $s) {
            if ($s !== '' && isset($allowed[$s])) {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Direct user grants that can be toggled in CRM permissions UI (nav subpages).
     *
     * @return list<string>
     */
    public static function editableCrmPermissionKeys(): array
    {
        $keys = CrmStaffPermissionCatalog::matrixEditableKeys();
        Permission::ensureRowsForKeys($keys);
        Permission::ensureRowsForKeys(CrmStaffPermissionCatalog::allDefinitionKeys());

        return $keys;
    }

    private static function isAssignableCrmPermissionKey(string $key): bool
    {
        $dot = strrpos($key, '.');
        if ($dot === false) {
            return false;
        }
        $module = strtolower(substr($key, 0, $dot));
        if (in_array($module, self::ADMIN_ONLY_CRM_MODULES, true)) {
            return false;
        }
        // Coarse legacy modules are not assignable in the matrix (replaced by subpages).
        if (in_array($module, CrmStaffPermissionCatalog::legacyMatrixModules(), true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<mixed>  $keys
     * @return list<string>
     */
    public static function permissionKeyStringsOnly(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (! is_string($k)) {
                continue;
            }
            $t = trim($k);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return array_values(array_unique($out));
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'last_login_at',
        'last_login_ip',
        'legacy_user_id',
        'client_account_id',
        'account_user_role',
        'is_account_primary',
        'shiphero_refresh_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'shiphero_refresh_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_account_primary' => 'boolean',
        'shiphero_refresh_token' => 'encrypted',
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

    /** Client CRM rows where this user is the assigned account manager (staff). */
    public function managedClientAccounts(): HasMany
    {
        return $this->hasMany(ClientAccount::class, 'account_manager_id');
    }

    /** 3PL client portal account linked to this login (inverse of account manager). */
    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
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

        return CrmStaffPermissionCatalog::grants($this->allPermissionKeys(), $key);
    }

    /**
     * Effective CRM keys for the client (expands legacy module grants into subpage keys).
     *
     * @return list<string>
     */
    public function effectiveCrmPermissionKeys(): array
    {
        $raw = $this->allPermissionKeys();
        $out = [];
        foreach ($raw as $key) {
            $out[] = $key;
            foreach (CrmStaffPermissionCatalog::expandLegacyKey($key) as $child) {
                $out[] = $child;
            }
        }

        return array_values(array_unique($out));
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

        return array_values(array_unique(array_merge(
            self::permissionKeyStringsOnly($fromRoles),
            self::permissionKeyStringsOnly($fromDirect),
        )));
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

    public function isCrmStaffUser(): bool
    {
        return $this->client_account_id === null;
    }

    public function shipheroRefreshToken(): ?string
    {
        $token = trim((string) ($this->shiphero_refresh_token ?? ''));

        return $token !== '' ? $token : null;
    }

    /**
     * Direct user→permission grants limited to CRM module keys (Staff / Webmaster matrix).
     *
     * @return list<string>
     */
    public function directCrmPermissionKeys(): array
    {
        $this->loadMissing('permissions');
        $allowed = array_flip(self::editableCrmPermissionKeys());
        $out = [];
        foreach ($this->permissions as $permission) {
            $raw = $permission->getAttribute('key');
            if (! is_string($raw)) {
                continue;
            }
            $k = trim($raw);
            if ($k !== '' && isset($allowed[$k])) {
                $out[] = $k;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Direct grants for the permissions matrix UI (expands legacy module keys into subpages).
     *
     * @return list<string>
     */
    public function directCrmPermissionKeysForMatrix(): array
    {
        $this->loadMissing('permissions');
        $allowed = array_flip(self::editableCrmPermissionKeys());
        $out = [];
        foreach ($this->permissions as $permission) {
            $raw = $permission->getAttribute('key');
            if (! is_string($raw)) {
                continue;
            }
            $k = trim($raw);
            if ($k === '') {
                continue;
            }
            if (isset($allowed[$k])) {
                $out[] = $k;
                continue;
            }
            foreach (CrmStaffPermissionCatalog::expandLegacyKey($k) as $child) {
                if (isset($allowed[$child])) {
                    $out[] = $child;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Permission keys granted via roles (not direct user pivot), limited to CRM matrix keys.
     *
     * @return list<string>
     */
    public function roleCrmPermissionKeys(): array
    {
        $this->loadMissing('roles.permissions');
        $allowed = array_flip(self::editableCrmPermissionKeys());
        $out = [];
        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $raw = $permission->getAttribute('key');
                if (! is_string($raw)) {
                    continue;
                }
                $k = trim($raw);
                if ($k !== '' && isset($allowed[$k])) {
                    $out[] = $k;
                }
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

        if ($this->client_account_id !== null) {
            $this->loadMissing('clientAccount:id,company_name,status,shiphero_customer_account_id');
        }

        $arr = $this->toArray();
        unset($arr['permissions']);

        $clientAccountCompanyName = null;
        $clientAccountStatus = null;
        $shipheroReady = false;
        $portalSetupComplete = false;
        if ($this->client_account_id !== null && $this->clientAccount !== null) {
            $account = $this->clientAccount;
            $name = trim((string) $account->company_name);
            $clientAccountCompanyName = $name !== '' ? $name : null;
            $clientAccountStatus = (string) $account->status;
            $sid = trim((string) ($account->shiphero_customer_account_id ?? ''));
            $shipheroReady = $sid !== '';
            $portalSetupComplete = $clientAccountStatus === ClientAccount::STATUS_ACTIVE
                && $shipheroReady
                && $this->status === 'active';
        }

        return array_merge($arr, [
            'permission_keys' => $this->effectiveCrmPermissionKeys(),
            'direct_permission_keys' => $this->directCrmPermissionKeysForMatrix(),
            'role_permission_keys' => $this->roleCrmPermissionKeys(),
            'is_admin' => $this->isAdministrator(),
            'is_crm_owner' => $this->isCrmOwner(),
            'client_account_id' => $this->client_account_id,
            'client_account_company_name' => $clientAccountCompanyName,
            'client_account_status' => $clientAccountStatus,
            'shiphero_ready' => $shipheroReady,
            'portal_setup_complete' => $portalSetupComplete,
        ]);
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new \App\Notifications\CrmResetPasswordNotification($token));
    }
}

