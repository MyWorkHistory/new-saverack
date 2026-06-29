<?php

namespace App\Support;

use App\Models\User;

class InventoryAdjustmentActor
{
    public static function label(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }
        $name = trim((string) $user->name);
        if ($name !== '') {
            return $name;
        }
        $email = trim((string) $user->email);

        return $email !== '' ? $email : null;
    }

    public static function reasonWithActor(string $reason, ?User $user): string
    {
        $base = trim($reason);
        if ($base === '') {
            return $base;
        }
        $actor = self::label($user);
        if ($actor === null || $actor === '') {
            return $base;
        }
        $suffix = ' ('.$actor.')';
        if (self::endsWith($base, $suffix)) {
            return $base;
        }
        $maxLen = 500;
        if (strlen($base) + strlen($suffix) > $maxLen) {
            $base = rtrim(substr($base, 0, max(0, $maxLen - strlen($suffix))));
        }

        return $base.$suffix;
    }

    private static function endsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);

        return substr($haystack, -$len) === $needle;
    }
}
