<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ShipHeroCredentialResolver;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class ShipHeroCredentialResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_staff_user_with_token_uses_user_source(): void
    {
        config(['crm.owner_email' => 'owner@saverack.com']);
        config(['services.shiphero.refresh_token' => 'env-token']);

        $staff = new User([
            'email' => 'staff@saverack.com',
            'client_account_id' => null,
            'shiphero_refresh_token' => 'staff-token',
        ]);

        $resolver = new ShipHeroCredentialResolver();

        $this->assertSame('staff-token', $resolver->resolveRefreshToken($staff));
        $this->assertSame(ShipHeroCredentialResolver::SOURCE_USER, $resolver->credentialSource($staff));
    }

    public function test_staff_user_without_token_falls_back_to_owner(): void
    {
        config(['crm.owner_email' => 'audi@saverack.com']);
        config(['services.shiphero.refresh_token' => 'env-token']);

        $owner = new User([
            'email' => 'audi@saverack.com',
            'client_account_id' => null,
            'shiphero_refresh_token' => 'audi-token',
        ]);

        $staff = new User([
            'email' => 'staff@saverack.com',
            'client_account_id' => null,
            'shiphero_refresh_token' => null,
        ]);

        $resolver = Mockery::mock(ShipHeroCredentialResolver::class)->makePartial();
        $resolver->shouldReceive('crmOwnerUser')->andReturn($owner);

        $this->assertSame('audi-token', $resolver->resolveRefreshToken($staff));
        $this->assertSame(ShipHeroCredentialResolver::SOURCE_OWNER, $resolver->credentialSource($staff));
    }

    public function test_unauthenticated_request_falls_back_to_owner(): void
    {
        config(['crm.owner_email' => 'audi@saverack.com']);
        config(['services.shiphero.refresh_token' => 'env-token']);

        $owner = new User([
            'email' => 'audi@saverack.com',
            'client_account_id' => null,
            'shiphero_refresh_token' => 'audi-token',
        ]);

        $resolver = Mockery::mock(ShipHeroCredentialResolver::class)->makePartial();
        $resolver->shouldReceive('crmOwnerUser')->andReturn($owner);

        $this->assertSame('audi-token', $resolver->resolveRefreshToken(null));
        $this->assertSame(ShipHeroCredentialResolver::SOURCE_OWNER, $resolver->credentialSource(null));
    }

    public function test_portal_user_without_staff_token_uses_owner_not_portal_row(): void
    {
        config(['crm.owner_email' => 'audi@saverack.com']);
        config(['services.shiphero.refresh_token' => 'env-token']);

        $owner = new User([
            'email' => 'audi@saverack.com',
            'client_account_id' => null,
            'shiphero_refresh_token' => 'audi-token',
        ]);

        $portalUser = new User([
            'email' => 'portal@client.com',
            'client_account_id' => 999,
            'shiphero_refresh_token' => 'portal-token',
        ]);

        $resolver = Mockery::mock(ShipHeroCredentialResolver::class)->makePartial();
        $resolver->shouldReceive('crmOwnerUser')->andReturn($owner);

        $this->assertSame('audi-token', $resolver->resolveRefreshToken($portalUser));
        $this->assertSame(ShipHeroCredentialResolver::SOURCE_OWNER, $resolver->credentialSource($portalUser));
    }

    public function test_falls_back_to_env_when_owner_has_no_token(): void
    {
        config(['crm.owner_email' => 'audi@saverack.com']);
        config(['services.shiphero.refresh_token' => 'env-token']);

        $owner = new User([
            'email' => 'audi@saverack.com',
            'client_account_id' => null,
            'shiphero_refresh_token' => null,
        ]);

        $resolver = Mockery::mock(ShipHeroCredentialResolver::class)->makePartial();
        $resolver->shouldReceive('crmOwnerUser')->andReturn($owner);

        $this->assertSame('env-token', $resolver->resolveRefreshToken(null));
        $this->assertSame(ShipHeroCredentialResolver::SOURCE_ENV, $resolver->credentialSource(null));
    }

    public function test_throws_when_no_tokens_configured(): void
    {
        config(['crm.owner_email' => 'audi@saverack.com']);
        config(['services.shiphero.refresh_token' => '']);

        $owner = new User([
            'email' => 'audi@saverack.com',
            'client_account_id' => null,
            'shiphero_refresh_token' => null,
        ]);

        $resolver = Mockery::mock(ShipHeroCredentialResolver::class)->makePartial();
        $resolver->shouldReceive('crmOwnerUser')->andReturn($owner);

        $this->expectException(RuntimeException::class);
        $resolver->resolveRefreshToken(null);
    }
}
