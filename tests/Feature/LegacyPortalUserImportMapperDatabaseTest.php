<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Support\Legacy\LegacyPortalUserImportMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LegacyPortalUserImportMapperDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_account_from_linked_customer_company_name(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Comeback Commerce',
            'email' => 'admin@comebackcommerce.com',
            'status' => ClientAccount::STATUS_ACTIVE,
            'legacy_customer_id' => null,
        ]);

        $legacyCustomer = (object) [
            'id' => 118,
            'company_name' => 'Comeback Commerce',
            'c_email' => 'billing@comebackcommerce.com',
        ];

        $userRow = (object) [
            'id' => 150,
            'email' => 'cs@comebackcommerce.com',
            'customers' => null,
        ];

        $found = LegacyPortalUserImportMapper::findClientAccountForPortalUser(
            $userRow,
            null,
            [$legacyCustomer]
        );

        $this->assertNotNull($found);
        $this->assertSame($account->id, $found->id);
    }
}
