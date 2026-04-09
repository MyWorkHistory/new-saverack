<?php

namespace App\Support;

use App\Models\ActivityLog;

/**
 * Timeline copy for CRM activity logs (staff users, client accounts, portal users).
 */
final class CrmActivityPresenter
{
    public static function formatLogLine(ActivityLog $log): string
    {
        $actorName = $log->relationLoaded('user') && $log->user
            ? (string) $log->user->name
            : 'System';
        $action = (string) $log->action;
        $meta = is_array($log->metadata) ? $log->metadata : [];

        if ($action === 'client_account.created') {
            $n = isset($meta['company_name']) ? (string) $meta['company_name'] : 'Account';

            return "{$actorName} created client account: {$n}";
        }

        if ($action === 'client_account.updated') {
            return "{$actorName} updated account details";
        }

        if ($action === 'client_account.comment') {
            return "{$actorName} posted a note";
        }

        if ($action === 'portal_user.created') {
            $e = isset($meta['email']) ? (string) $meta['email'] : 'user';

            return "{$actorName} created portal user: {$e}";
        }

        if ($action === 'portal_user.updated') {
            return "{$actorName} updated portal user";
        }

        if ($action === 'portal_user.deleted') {
            $e = isset($meta['email']) ? (string) $meta['email'] : 'user';

            return "{$actorName} removed portal user: {$e}";
        }

        return UserStaffHistory::formatLogLine($log);
    }

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

    /**
     * @return array<string, mixed>
     */
    public static function toHistoryItem(ActivityLog $log): array
    {
        $actorName = ($log->relationLoaded('user') && $log->user)
            ? (string) $log->user->name
            : null;
        $actorAvatarUrl = null;
        if ($log->relationLoaded('user') && $log->user && $log->user->relationLoaded('profile')) {
            $p = $log->user->profile;
            $actorAvatarUrl = $p !== null ? $p->avatar_url : null;
        }

        return [
            'id' => $log->id,
            'created_at' => $log->created_at !== null ? $log->created_at->toIso8601String() : null,
            'actor_name' => $actorName !== null ? $actorName : 'System',
            'actor_initials' => UserStaffHistory::initials($actorName),
            'actor_avatar_url' => $actorAvatarUrl,
            'line' => self::formatLogLine($log),
            'body' => self::formatLogBody($log),
        ];
    }
}
