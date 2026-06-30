<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ShipHeroCredentialResolver;
use RuntimeException;
use Tests\TestCase;

final class ShipHeroCredentialResolverTest extends TestCase
{
    public function test_always_uses_env_refresh_token_for_staff_user(): void
    {
        config(['services.shiphero.refresh_token' => 'env-token']);

        $staff = new User([
            'email' => 'staff@saverack.com',
            'client_account_id' => null,
            'shiphero_refresh_token' => 'staff-token',
        ]);

        $resolver = new ShipHeroCredentialResolver();

        $this->assertSame('env-token', $resolver->resolveRefreshToken($staff));
        $this->assertSame(ShipHeroCredentialResolver::SOURCE_ENV, $resolver->credentialSource($staff));
    }

    public function test_always_uses_env_refresh_token_when_unauthenticated(): void
    {
        config(['services.shiphero.refresh_token' => 'env-token']);

        $resolver = new ShipHeroCredentialResolver();

        $this->assertSame('env-token', $resolver->resolveRefreshToken(null));
        $this->assertSame(ShipHeroCredentialResolver::SOURCE_ENV, $resolver->credentialSource(null));
    }

    public function test_throws_when_env_refresh_token_missing(): void
    {
        config(['services.shiphero.refresh_token' => '']);

        $resolver = new ShipHeroCredentialResolver();

        $this->expectException(RuntimeException::class);
        $resolver->resolveRefreshToken(null);
    }
}
