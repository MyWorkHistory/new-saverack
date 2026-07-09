<?php

namespace App\Support\Legacy;

use Carbon\Carbon;

/**
 * Maps legacy `users` rows (Save Rack staff) into new CRM `users` + `user_profiles`.
 */
final class LegacyStaffUserImportMapper
{
    public static function isImportableStaffRow(object $row): bool
    {
        $userType = self::nonEmptyString($row->userType ?? null);
        if ($userType === null || strcasecmp($userType, 'Employee') !== 0) {
            return false;
        }

        $status = isset($row->status) ? (int) $row->status : 0;
        if ($status !== 2) {
            return false;
        }

        $isDeleted = isset($row->is_deleted) ? (int) $row->is_deleted : 1;

        return $isDeleted === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapUserFields(object $row, ?string $passwordHash = null): array
    {
        $legacyId = isset($row->id) ? (int) $row->id : 0;
        $email = self::normEmail(self::nonEmptyString($row->email ?? null) ?? '');
        $name = self::resolveName($row, $email);

        $attrs = [
            'legacy_user_id' => $legacyId > 0 ? $legacyId : null,
            'name' => self::truncate($name, 150),
            'email' => self::truncate($email, 190),
            'status' => 'active',
            'client_account_id' => null,
            'account_user_role' => null,
            'is_account_primary' => false,
            'last_login_at' => self::parseTimestamp($row->last_logged_in_at ?? null),
            'last_login_ip' => self::nullableTruncate($row->ip_address ?? null, 45),
        ];

        if ($passwordHash !== null && $passwordHash !== '') {
            $attrs['password'] = $passwordHash;
        }

        return array_filter($attrs, static function ($v) {
            return $v !== null;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapProfileFields(object $row): array
    {
        $legacyRole = isset($row->role) && is_numeric($row->role) ? (int) $row->role : null;

        $legacyFields = array_filter([
            'manager' => self::intOrNull($row->manager ?? null),
            'manager_name' => self::nonEmptyString($row->manager_name ?? null),
        ], static function ($v) {
            return $v !== null && $v !== '';
        });

        $attrs = array_filter([
            'phone' => self::nullableTruncate($row->phone ?? null, 50),
            'avatar_path' => self::nullableTruncate($row->avatar ?? null, 255),
            'user_type' => self::nullableTruncate($row->userType ?? null, 50),
            'tag' => self::nullableTruncate($row->tag ?? null, 100),
            'skype' => self::nullableTruncate($row->skype ?? null, 100),
            'telegram' => self::nullableTruncate($row->telegram ?? null, 100),
            'slack' => self::nullableTruncate($row->slack ?? null, 255),
            'slack_member_id' => self::nullableTruncate($row->slack_member_id ?? null, 100),
            'bio' => self::nonEmptyString($row->bio ?? null),
            'html_bio' => self::nonEmptyString($row->html_bio ?? null),
            'birthday' => self::parseDate($row->birthday ?? null),
            'personal_email' => self::nullableTruncate($row->personal_email ?? null, 190),
            'address' => self::nullableTruncate($row->address ?? null, 255),
            'city' => self::nullableTruncate($row->city ?? null, 120),
            'state' => self::nullableTruncate($row->state ?? null, 120),
            'zip' => self::nullableTruncate($row->zip ?? null, 32),
            'region' => self::nullableTruncate($row->region ?? null, 120),
            'employee_type' => self::mapEmployeeType($row->employeeType ?? null),
            'pin' => self::nullableTruncate($row->pin ?? null, 20),
            'month' => self::tinyIntOrNull($row->month ?? null),
            'day' => self::tinyIntOrNull($row->day ?? null),
            'hours' => self::decimalOrNull($row->hours ?? null),
            'full_hours' => self::decimalOrNull($row->full_hours ?? null),
            'half_hours' => self::decimalOrNull($row->half_hours ?? null),
            'pto' => self::decimalOrNull($row->pto ?? null),
            'pto_accrual_rate' => self::decimalOrNull($row->pto_accrual_rate ?? null),
            'sick_days' => self::decimalOrNull($row->sick_days ?? null),
            'absence' => self::decimalOrNull($row->absence ?? null),
            'holiday' => self::decimalOrNull($row->holiday ?? null),
            'remote' => self::decimalOrNull($row->remote ?? null),
            'other' => self::decimalOrNull($row->other ?? null),
            'late' => self::decimalOrNull($row->late ?? null),
            'salary' => self::decimalOrNull($row->salary ?? null),
            'hire_date' => self::parseDate($row->hireDate ?? null),
            'terminate_date' => self::parseDate($row->terminateDate ?? null),
            'terminate_reason' => self::nonEmptyString($row->reason ?? null),
            'quote' => self::nullableTruncate($row->quote ?? null, 255),
            'quote_date' => self::parseDate($row->quoteDate ?? null),
            'lunch_status' => self::nullableTruncate($row->lunch_status ?? null, 50),
            'punch_status' => self::nullableTruncate($row->punch_status ?? null, 50),
            'punch_time' => self::nullableTruncate($row->punch_time ?? null, 50),
            'is_clock' => self::boolFromLegacyClock($row->is_clock ?? null),
            'crm_access' => self::yesNoToBool($row->crm_access ?? null),
            'wh_access' => self::yesNoToBool($row->wh_access ?? null),
            'is_permission' => self::boolFromInt($row->is_permission ?? null),
            'is_email' => self::boolFromInt($row->is_email ?? null),
            'is_deleted_soft' => self::isDeletedSoft($row->is_deleted ?? null),
            'owner' => self::nullableTruncate($row->owner ?? null, 100),
            'updated_by_user_id' => self::intOrNull($row->updated_by_user_id ?? null),
            'chat' => self::nullableTruncate($row->chat ?? null, 100),
            'platform' => self::nullableTruncate($row->platform ?? null, 100),
            'manager_slack_channel' => self::nullableTruncate($row->manager_slack_channel ?? null, 255),
            'fulfillment_percent' => self::decimalOrNull($row->fulfillment_percent ?? null),
            'referral_percent' => self::decimalOrNull($row->referral_percent ?? null),
            'prepay_percent' => self::decimalOrNull($row->prepay_percent ?? null),
            'on_demand_percent' => self::decimalOrNull($row->on_demand_percent ?? null),
            'shipment_bonus' => self::decimalOrNull($row->shipment_bonus ?? null),
            'legacy_numeric_role' => $legacyRole,
            'legacy_fields' => $legacyFields !== [] ? $legacyFields : null,
        ], static function ($v) {
            return $v !== null;
        });

        return $attrs;
    }

    public static function resolveCrmRoleName(int $legacyRole): string
    {
        $adminRoles = config('legacy_staff_import.admin_legacy_roles', [4, 9]);
        if (! is_array($adminRoles)) {
            $adminRoles = [4, 9];
        }

        return in_array($legacyRole, $adminRoles, true) ? 'admin' : 'staff';
    }

    public static function normEmail(?string $email): string
    {
        if ($email === null) {
            return '';
        }

        return trim(strtolower($email));
    }

    public static function isValidEmail(string $email): bool
    {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
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

        return 'Legacy Staff';
    }

    /**
     * @param  mixed  $raw
     */
    private static function mapEmployeeType($raw): ?string
    {
        if (! is_numeric($raw)) {
            return null;
        }

        switch ((int) $raw) {
            case 1:
                return 'Full-Time';
            case 2:
                return 'Part-Time';
            case 3:
                return 'Temporary';
            default:
                return null;
        }
    }

    /**
     * @param  mixed  $raw
     */
    private static function yesNoToBool($raw): ?bool
    {
        $s = self::nonEmptyString($raw);
        if ($s === null) {
            return null;
        }

        $lower = strtolower($s);
        if (in_array($lower, ['yes', 'y', '1', 'true'], true)) {
            return true;
        }
        if (in_array($lower, ['no', 'n', '0', 'false'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @param  mixed  $raw
     */
    private static function boolFromInt($raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw === 1;
    }

    /**
     * @param  mixed  $raw
     */
    private static function boolFromLegacyClock($raw): ?bool
    {
        if (! is_numeric($raw)) {
            return null;
        }

        return (int) $raw === 1;
    }

    /**
     * @param  mixed  $raw
     */
    private static function isDeletedSoft($raw): ?bool
    {
        if (! is_numeric($raw)) {
            return null;
        }

        $v = (int) $raw;
        if ($v === 1) {
            return true;
        }
        if ($v === 2) {
            return false;
        }

        return null;
    }

    /**
     * @param  mixed  $raw
     */
    private static function parseDate($raw): ?string
    {
        if ($raw === null || $raw === '' || $raw === '0000-00-00') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
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
     * @param  mixed  $raw
     */
    private static function intOrNull($raw): ?int
    {
        if (! is_numeric($raw)) {
            return null;
        }

        $v = (int) $raw;

        return $v > 0 ? $v : null;
    }

    /**
     * @param  mixed  $raw
     */
    private static function tinyIntOrNull($raw): ?int
    {
        if (! is_numeric($raw)) {
            return null;
        }

        $v = (int) $raw;
        if ($v < 0 || $v > 255) {
            return null;
        }

        return $v;
    }

    /**
     * @param  mixed  $raw
     */
    private static function decimalOrNull($raw): ?float
    {
        if (! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
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
