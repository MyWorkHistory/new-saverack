<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class ShipHeroCredentialResolver
{
    public const SOURCE_USER = 'user';

    public const SOURCE_OWNER = 'owner';

    public const SOURCE_ENV = 'env';

    public function resolveRefreshToken(?User $user = null): string
    {
        [$token] = $this->resolve($user);

        return $token;
    }

    public function credentialSource(?User $user = null): string
    {
        [, $source] = $this->resolve($user);

        return $source;
    }

    public function crmOwnerUser(): ?User
    {
        $email = config('crm.owner_email');
        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->first();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolve(?User $user = null): array
    {
        $user = $user ?? Auth::user();

        if ($user instanceof User && $user->isCrmStaffUser()) {
            $staffToken = $user->shipheroRefreshToken();
            if ($staffToken !== null) {
                return [$staffToken, self::SOURCE_USER];
            }
        }

        $owner = $this->crmOwnerUser();
        if ($owner !== null) {
            $ownerToken = $owner->shipheroRefreshToken();
            if ($ownerToken !== null) {
                return [$ownerToken, self::SOURCE_OWNER];
            }
        }

        $env = config('services.shiphero.refresh_token');
        if (is_string($env) && trim($env) !== '') {
            return [trim($env), self::SOURCE_ENV];
        }

        throw new RuntimeException(
            'ShipHero is not configured: set SHIPHERO_REFRESH_TOKEN in .env or assign a user refresh token.'
        );
    }
}
