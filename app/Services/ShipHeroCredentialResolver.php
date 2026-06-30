<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

class ShipHeroCredentialResolver
{
    public const SOURCE_USER = 'user';

    public const SOURCE_OWNER = 'owner';

    public const SOURCE_ENV = 'env';

    public function resolveRefreshToken(?User $user = null): string
    {
        [$token] = $this->resolve();

        return $token;
    }

    public function credentialSource(?User $user = null): string
    {
        [, $source] = $this->resolve();

        return $source;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolve(): array
    {
        $env = config('services.shiphero.refresh_token');
        if (is_string($env) && trim($env) !== '') {
            return [trim($env), self::SOURCE_ENV];
        }

        throw new RuntimeException(
            'ShipHero is not configured: set SHIPHERO_REFRESH_TOKEN in .env.'
        );
    }
}
