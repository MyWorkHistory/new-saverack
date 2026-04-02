<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;

/**
 * Staff profile activity: snapshot/diff for {@see UserService} and line formatting for API/UI.
 */
final class UserStaffHistory
{
    /** @var array<string, string> */
    public const FIELD_LABELS = [
        'name' => 'Name',
        'email' => 'Login Email',
        'status' => 'Status',
        'phone' => 'Phone',
        'personal_email' => 'Email',
        'birthday' => 'Birthday',
        'address' => 'Street',
        'city' => 'City',
        'state' => 'State',
        'zip' => 'ZIP',
        'region' => 'Country',
        'employee_type' => 'Employment Type',
        'job_position' => 'Position',
        'hire_date' => 'Hire Date',
        'terminate_date' => 'Termination Date',
        'bio' => 'Bio',
        'role_ids' => 'Roles',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(User $user): array
    {
        $user->loadMissing(['profile', 'roles']);
        $p = $user->profile;

        return [
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'phone' => $p !== null ? $p->phone : null,
            'personal_email' => $p !== null ? $p->personal_email : null,
            'birthday' => $p !== null ? $p->birthday : null,
            'address' => $p !== null ? $p->address : null,
            'city' => $p !== null ? $p->city : null,
            'state' => $p !== null ? $p->state : null,
            'zip' => $p !== null ? $p->zip : null,
            'region' => $p !== null ? $p->region : null,
            'employee_type' => $p !== null ? $p->employee_type : null,
            'job_position' => $p !== null ? $p->job_position : null,
            'hire_date' => $p !== null ? $p->hire_date : null,
            'terminate_date' => $p !== null ? $p->terminate_date : null,
            'bio' => $p !== null ? $p->bio : null,
            'role_ids' => $user->roles->pluck('id')->sort()->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{field: string, from: string, to: string}>
     */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        foreach (array_keys(self::FIELD_LABELS) as $key) {
            if ($key === 'role_ids') {
                $a = $before['role_ids'] ?? [];
                $b = $after['role_ids'] ?? [];
                if (! is_array($a)) {
                    $a = [];
                }
                if (! is_array($b)) {
                    $b = [];
                }
                $a = array_values(array_unique(array_map('intval', $a)));
                $b = array_values(array_unique(array_map('intval', $b)));
                sort($a);
                sort($b);
                if ($a === $b) {
                    continue;
                }
                $out[] = [
                    'field' => self::FIELD_LABELS[$key],
                    'from' => self::formatRoleList($a),
                    'to' => self::formatRoleList($b),
                ];

                continue;
            }

            $normBefore = self::normalizeComparable($key, $before[$key] ?? null);
            $normAfter = self::normalizeComparable($key, $after[$key] ?? null);
            if ($normBefore === $normAfter) {
                continue;
            }
            $out[] = [
                'field' => self::FIELD_LABELS[$key],
                'from' => self::formatDisplayValue($key, $before[$key] ?? null, $normBefore),
                'to' => self::formatDisplayValue($key, $after[$key] ?? null, $normAfter),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $ids
     */
    public static function formatRoleList(array $ids): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 'null';
        }
        $labels = Role::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            ->map(fn (Role $r) => $r->label ?: $r->name)
            ->filter()
            ->values()
            ->all();

        return $labels !== [] ? implode(', ', $labels) : 'null';
    }

    /**
     * @param  mixed  $value
     */
    private static function normalizeComparable(string $key, $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d');
        }
        if (in_array($key, ['hire_date', 'terminate_date', 'birthday'], true)) {
            try {
                return Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable $e) {
                return is_string($value) ? trim($value) : (string) $value;
            }
        }

        return is_string($value) ? trim($value) : (string) $value;
    }

    /**
     * @param  mixed  $raw
     */
    private static function formatDisplayValue(string $key, $raw, ?string $normalized): string
    {
        if ($normalized === null) {
            return 'null';
        }
        if ($raw instanceof \DateTimeInterface) {
            return Carbon::instance($raw)->format('n/j/Y');
        }
        if (in_array($key, ['hire_date', 'terminate_date', 'birthday'], true)) {
            try {
                return Carbon::parse($raw)->format('n/j/Y');
            } catch (\Throwable $e) {
                return (string) $raw;
            }
        }

        return (string) $raw;
    }

    public static function formatLogLine(ActivityLog $log): string
    {
        $actorName = $log->relationLoaded('user') && $log->user
            ? (string) $log->user->name
            : 'System';
        $meta = is_array($log->metadata) ? $log->metadata : [];

        if ($log->action === 'user.created') {
            $n = isset($meta['new_user_name']) ? (string) $meta['new_user_name'] : 'User';

            return "{$actorName} created New User: {$n}";
        }

        if ($log->action === 'user.updated' && ($meta['kind'] ?? '') === 'password') {
            return "{$actorName} updated Password";
        }

        if ($log->action === 'user.updated' && isset($meta['field'])) {
            $field = (string) $meta['field'];
            $from = array_key_exists('from', $meta) ? (string) $meta['from'] : 'null';
            $to = array_key_exists('to', $meta) ? (string) $meta['to'] : 'null';
            if ($from === '') {
                $from = 'null';
            }
            if ($to === '') {
                $to = 'null';
            }

            return "{$actorName} edited {$field} from: {$from} to: {$to}";
        }

        if ($log->description) {
            return $actorName.': '.$log->description;
        }

        return $actorName.' performed '.($log->action ?: 'an action');
    }

    /** Text after the actor prefix (for two-line UI: actor + timestamp, then body). */
    public static function formatLogBody(ActivityLog $log): string
    {
        $actorName = $log->relationLoaded('user') && $log->user
            ? (string) $log->user->name
            : 'System';
        $line = self::formatLogLine($log);
        $prefix = $actorName.' ';
        if (strpos($line, $prefix) === 0) {
            return substr($line, strlen($prefix));
        }

        return $line;
    }

    public static function initials(?string $name): string
    {
        if ($name === null || $name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return '?';
        }
        $a = strtoupper(substr((string) ($parts[0][0] ?? ''), 0, 1));
        $b = count($parts) > 1
            ? strtoupper(substr((string) ($parts[count($parts) - 1][0] ?? ''), 0, 1))
            : '';

        return $b !== '' ? $a.$b : $a;
    }
}
