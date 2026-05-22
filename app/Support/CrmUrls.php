<?php

namespace App\Support;

class CrmUrls
{
    public static function frontendBase(): string
    {
        $base = config('crm.frontend_url');

        return rtrim(is_string($base) && $base !== '' ? $base : (string) config('app.url'), '/');
    }

    public static function resetPassword(string $token, string $email): string
    {
        $query = http_build_query([
            'token' => $token,
            'email' => $email,
        ]);

        return self::frontendBase().'/reset-password?'.$query;
    }

    public static function clientAccountStaffUrl(int $clientAccountId): string
    {
        return self::frontendBase().'/admin/clients/accounts/'.$clientAccountId;
    }

    public static function portalLoginUrl(): string
    {
        return self::frontendBase().'/login';
    }
}
