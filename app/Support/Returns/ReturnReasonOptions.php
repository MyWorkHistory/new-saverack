<?php

namespace App\Support\Returns;

final class ReturnReasonOptions
{
    /**
     * @return array<string, string>
     */
    public static function portal(): array
    {
        /** @var array<string, string> $reasons */
        $reasons = config('returns.return_reasons', []);

        return $reasons;
    }

    /**
     * @return array<string, string>
     */
    public static function admin(): array
    {
        /** @var array<string, string> $reasons */
        $reasons = config('returns.admin_return_reasons', []);

        return $reasons;
    }

    public static function adminDefaultKey(): string
    {
        return (string) config('returns.admin_default_return_reason', 'unknown');
    }

    public static function labelFor(?string $key, ?string $createdSource = null): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        $source = strtolower(trim((string) $createdSource));
        if ($source === 'admin') {
            return self::admin()[$key] ?? $key;
        }

        return self::portal()[$key] ?? self::admin()[$key] ?? $key;
    }

    public static function isValidPortalKey(string $key): bool
    {
        return array_key_exists($key, self::portal());
    }

    public static function isValidAdminKey(string $key): bool
    {
        return array_key_exists($key, self::admin());
    }

    /**
     * @return array<string, string>
     */
    public static function nonCompliant(): array
    {
        /** @var array<string, string> $reasons */
        $reasons = config('returns.non_compliant_reasons', []);

        return $reasons;
    }

    public static function nonCompliantLabel(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return self::nonCompliant()[$key] ?? $key;
    }

    public static function isValidNonCompliantKey(string $key): bool
    {
        return array_key_exists($key, self::nonCompliant());
    }
}
