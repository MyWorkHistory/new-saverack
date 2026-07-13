<?php

namespace App\Support\Legacy;

use App\Models\ClientAccount;
use App\Models\User;
use Carbon\Carbon;

/**
 * Maps legacy `users` rows (client portal) into CRM portal `users` + `user_profiles`.
 */
final class LegacyPortalUserImportMapper
{
    public static function isImportablePortalRow(
        object $row,
        bool $includePending = false,
        bool $includeInactive = false,
        bool $includeDeleted = false
    ): bool {
        $userType = self::nonEmptyString($row->userType ?? null);
        if ($userType === null || strcasecmp($userType, 'User') !== 0) {
            return false;
        }

        $legacyRole = isset($row->role) && is_numeric($row->role) ? (int) $row->role : 0;
        $portalRoles = config('legacy_portal_import.portal_legacy_roles', [1, 2]);
        if (! is_array($portalRoles) || ! in_array($legacyRole, $portalRoles, true)) {
            return false;
        }

        $status = isset($row->status) ? (int) $row->status : 0;
        $allowedStatuses = [2];
        if ($includePending) {
            $allowedStatuses[] = 1;
        }
        if ($includeInactive) {
            $allowedStatuses[] = 3;
        }
        if (! in_array($status, $allowedStatuses, true)) {
            return false;
        }

        $isDeleted = isset($row->is_deleted) ? (int) $row->is_deleted : 1;
        if ($isDeleted === 2 && ! $includeDeleted) {
            return false;
        }

        return true;
    }

    public static function findClientAccountForPortalUser(object $row, ?object $legacyCustomerRow = null): ?ClientAccount
    {
        $email = LegacyStaffUserImportMapper::normEmail($row->email ?? null);
        if (LegacyStaffUserImportMapper::isValidEmail($email)) {
            $byAccountEmail = ClientAccount::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->orderBy('id')
                ->first();
            if ($byAccountEmail !== null) {
                return $byAccountEmail;
            }

            $byNotificationEmail = ClientAccount::query()
                ->whereRaw('LOWER(TRIM(notification_email)) = ?', [$email])
                ->orderBy('id')
                ->first();
            if ($byNotificationEmail !== null) {
                return $byNotificationEmail;
            }
        }

        if ($legacyCustomerRow !== null) {
            return LegacyCustomerAccountImportMapper::findClientAccountForLegacyRow($legacyCustomerRow);
        }

        $customerIds = self::parseLegacyCustomerIds($row->customers ?? null);
        foreach ($customerIds as $customerId) {
            $byLegacyId = ClientAccount::query()
                ->where('legacy_customer_id', $customerId)
                ->orderBy('id')
                ->first();
            if ($byLegacyId !== null) {
                return $byLegacyId;
            }
        }

        return null;
    }

    public static function isPrimaryPortalUser(object $row, ClientAccount $account): bool
    {
        $email = LegacyStaffUserImportMapper::normEmail($row->email ?? null);
        $accountEmail = LegacyStaffUserImportMapper::normEmail($account->email ?? null);

        return $email !== '' && $accountEmail !== '' && $email === $accountEmail;
    }

    public static function resolveAccountUserRole(int $legacyRole): string
    {
        if ($legacyRole === 1) {
            return User::ACCOUNT_USER_ROLE_CUSTOMER_SERVICE;
        }

        return User::ACCOUNT_USER_ROLE_ADMIN;
    }

    public static function mapLegacyStatus(int $status): string
    {
        if ($status === 1) {
            return 'pending';
        }
        if ($status === 2) {
            return 'active';
        }

        return 'inactive';
    }

    public static function resolvePasswordHash(object $row, string $fallbackHash): string
    {
        $hash = self::nonEmptyString($row->password ?? null);
        if ($hash !== null && str_starts_with($hash, '$2y$') && strlen($hash) >= 60) {
            return $hash;
        }

        return $fallbackHash;
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapUserFields(
        object $row,
        ClientAccount $account,
        string $passwordHash,
        bool $allowPrimary
    ): array {
        $legacyId = isset($row->id) ? (int) $row->id : 0;
        $email = LegacyStaffUserImportMapper::normEmail($row->email ?? null);
        $name = self::resolveName($row, $email);
        $legacyRole = isset($row->role) && is_numeric($row->role) ? (int) $row->role : 2;
        $legacyStatus = isset($row->status) ? (int) $row->status : 2;

        $isPrimary = $allowPrimary && self::isPrimaryPortalUser($row, $account);

        return [
            'legacy_user_id' => $legacyId > 0 ? $legacyId : null,
            'name' => self::truncate($name, 150),
            'email' => self::truncate($email, 190),
            'password' => $passwordHash,
            'status' => self::mapLegacyStatus($legacyStatus),
            'client_account_id' => $account->id,
            'account_user_role' => self::resolveAccountUserRole($legacyRole),
            'is_account_primary' => $isPrimary,
            'last_login_at' => self::parseTimestamp($row->last_logged_in_at ?? null),
            'last_login_ip' => self::nullableTruncate($row->ip_address ?? null, 45),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapProfileFields(object $row): array
    {
        $avatar = self::nullableTruncate($row->avatar ?? null, 255);
        if ($avatar !== null && str_contains(strtolower($avatar), 'default.png')) {
            $avatar = null;
        }

        return array_filter([
            'phone' => self::nullableTruncate($row->phone ?? null, 50),
            'avatar_path' => $avatar,
            'tag' => self::nullableTruncate($row->tag ?? null, 100),
            'bio' => self::nonEmptyString($row->bio ?? null),
            'owner' => self::nullableTruncate($row->owner ?? null, 100),
            'legacy_numeric_role' => isset($row->role) && is_numeric($row->role) ? (int) $row->role : null,
        ], static function ($v) {
            return $v !== null;
        });
    }

    /**
     * @return list<int>
     */
    public static function parseLegacyCustomerIds($raw): array
    {
        $s = self::nonEmptyString($raw);
        if ($s === null) {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', $s);
        if (! is_array($parts)) {
            return [];
        }

        $ids = [];
        foreach ($parts as $part) {
            if (is_numeric($part)) {
                $id = (int) $part;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private static function resolveName(object $row, string $email): string
    {
        $full = self::nonEmptyString($row->full_name ?? null);
        if ($full !== null) {
            return $full;
        }

        if ($email !== '' && strpos($email, '@') !== false) {
            return (string) strstr($email, '@', true);
        }

        return 'Portal User';
    }

    /**
     * @param  mixed  $raw
     */
    private static function parseTimestamp($raw): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param  mixed  $v
     */
    private static function nonEmptyString($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    /**
     * @param  mixed  $v
     */
    private static function nullableTruncate($v, int $max): ?string
    {
        $s = self::nonEmptyString($v);

        return $s !== null ? self::truncate($s, $max) : null;
    }

    private static function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max);
    }
}
