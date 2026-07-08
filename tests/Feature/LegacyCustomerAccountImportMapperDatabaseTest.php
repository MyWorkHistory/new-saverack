<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Legacy\LegacyCustomerAccountImportMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LegacyCustomerAccountImportMapperDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        LegacyCustomerAccountImportMapper::clearManagerCache();
    }

    public function test_resolves_account_manager_by_legacy_user_id(): void
    {
        $user = User::factory()->create([
            'name' => 'Eda Abretil',
            'legacy_user_id' => 120,
        ]);

        $resolved = LegacyCustomerAccountImportMapper::resolveAccountManagerId(120, 'Eda Abretil');

        $this->assertSame($user->id, $resolved);
    }

    public function test_resolves_account_manager_by_name_fallback(): void
    {
        $user = User::factory()->create([
            'name' => 'Taylor May',
            'legacy_user_id' => null,
        ]);

        $resolved = LegacyCustomerAccountImportMapper::resolveAccountManagerId(999, 'Taylor May');

        $this->assertSame($user->id, $resolved);
    }
}
