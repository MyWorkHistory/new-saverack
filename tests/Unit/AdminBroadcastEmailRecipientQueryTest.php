<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\AdminBroadcastEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Requires a running test database (MySQL). Skipped when DB is unreachable.
 */
final class AdminBroadcastEmailRecipientQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: '.$e->getMessage());
        }
    }

    public function test_recipient_query_excludes_inactive_and_non_primary(): void
    {
        $active = ClientAccount::query()->create([
            'company_name' => 'Active Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'active@test.com',
        ]);
        $paused = ClientAccount::query()->create([
            'company_name' => 'Paused Co',
            'status' => ClientAccount::STATUS_PAUSED,
            'email' => 'paused@test.com',
        ]);
        $inactive = ClientAccount::query()->create([
            'company_name' => 'Inactive Co',
            'status' => ClientAccount::STATUS_INACTIVE,
            'email' => 'inactive@test.com',
        ]);

        $primaryActive = User::factory()->create([
            'client_account_id' => $active->id,
            'is_account_primary' => true,
            'email' => 'primary-active@test.com',
        ]);
        User::factory()->create([
            'client_account_id' => $active->id,
            'is_account_primary' => false,
            'email' => 'secondary-active@test.com',
        ]);
        $primaryPaused = User::factory()->create([
            'client_account_id' => $paused->id,
            'is_account_primary' => true,
            'email' => 'primary-paused@test.com',
        ]);
        User::factory()->create([
            'client_account_id' => $inactive->id,
            'is_account_primary' => true,
            'email' => 'primary-inactive@test.com',
        ]);

        $ids = app(AdminBroadcastEmailService::class)
            ->recipientQuery()
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing(
            [$primaryActive->id, $primaryPaused->id],
            $ids
        );
    }
}
