<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
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

    public function test_prefers_manager_name_over_wrong_legacy_user_id(): void
    {
        $wrongLegacy = User::factory()->create([
            'name' => 'Wrong Person',
            'legacy_user_id' => 120,
            'client_account_id' => null,
        ]);
        $correct = User::factory()->create([
            'name' => 'Eda Abretil',
            'legacy_user_id' => null,
            'client_account_id' => null,
        ]);

        $resolved = LegacyCustomerAccountImportMapper::resolveAccountManagerId(120, 'Eda Abretil');

        $this->assertSame($correct->id, $resolved);
        $this->assertNotSame($wrongLegacy->id, $resolved);
    }

    public function test_finds_client_account_by_company_name_when_legacy_id_missing(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'TMDK Ventures LLC',
            'email' => 'audi@tmdkventures.com',
            'status' => ClientAccount::STATUS_ACTIVE,
            'legacy_customer_id' => null,
        ]);

        $row = (object) [
            'id' => 28,
            'company_name' => 'TMDK Ventures LLC',
            'c_email' => 'audi@tmdkventures.com',
        ];

        $found = LegacyCustomerAccountImportMapper::findClientAccountForLegacyRow($row);

        $this->assertNotNull($found);
        $this->assertSame($account->id, $found->id);
    }

    public function test_maps_account_manager_when_account_matched_by_company_name(): void
    {
        $manager = User::factory()->create([
            'name' => 'Eda Abretil',
            'client_account_id' => null,
        ]);

        ClientAccount::query()->create([
            'company_name' => 'TMDK Ventures LLC',
            'email' => 'audi@tmdkventures.com',
            'status' => ClientAccount::STATUS_ACTIVE,
            'legacy_customer_id' => null,
        ]);

        $row = (object) [
            'manager' => 120,
            'manager_name' => 'Eda Abretil',
            'activeDate' => '2024-08-05',
            'created_at' => '2021-09-02 17:31:29',
            'slack_channel' => 'tmdk',
            'c_email' => 'audi@tmdkventures.com',
        ];

        $mapped = LegacyCustomerAccountImportMapper::mapEnrichmentFields($row, 'audi@tmdkventures.com');

        $this->assertSame($manager->id, $mapped['account_manager_id']);
    }
}
