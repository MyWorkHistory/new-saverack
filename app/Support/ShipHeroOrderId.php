<?php

namespace App\Support;

class ShipHeroOrderId
{
    /**
     * Numeric dashboard / legacy id, or null if it cannot be derived.
     */
    public static function legacyId(?string $raw): ?string
    {
        $id = trim((string) $raw);
        if ($id === '') {
            return null;
        }
        if (ctype_digit($id)) {
            return $id;
        }

        $decoded = base64_decode($id, true);
        if (! is_string($decoded) || $decoded === '') {
            return null;
        }
        if (preg_match('/^Order:(\d+)$/i', $decoded, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public static function matches(?string $stored, ?string $incoming): bool
    {
        $a = trim((string) $stored);
        $b = trim((string) $incoming);
        if ($a === '' || $b === '') {
            return false;
        }
        if (strcasecmp($a, $b) === 0) {
            return true;
        }

        $legacyA = self::legacyId($a);
        $legacyB = self::legacyId($b);
        if ($legacyA !== null && $legacyB !== null && $legacyA === $legacyB) {
            return true;
        }

        return false;
    }
}
