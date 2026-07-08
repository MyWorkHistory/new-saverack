<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportShipHeroCustomerAccountIdsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_matches_company_name_and_sets_shiphero_id(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Esas Beauty',
            'email' => 'esas@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        $csv = $this->writeTempCsv(<<<'CSV'
Name,Warehouse,Customer Account ID
"Esas Beauty",Primary,93811
CSV
        );

        $this->artisan('crm:import-shiphero-customer-ids', ['file' => $csv])
            ->assertExitCode(0);

        $account->refresh();
        $this->assertSame('93811', $account->shiphero_customer_account_id);
    }

    public function test_import_matches_brand_name_when_company_differs(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Esas Holdings LLC',
            'brand_name' => 'Esas Beauty',
            'email' => 'esas@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        $csv = $this->writeTempCsv(<<<'CSV'
Name,Customer Account ID
Esas Beauty,93811
CSV
        );

        $this->artisan('crm:import-shiphero-customer-ids', ['file' => $csv])
            ->assertExitCode(0);

        $account->refresh();
        $this->assertSame('93811', $account->shiphero_customer_account_id);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Crosskix',
            'email' => 'crosskix@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        $csv = $this->writeTempCsv(<<<'CSV'
Name,Customer Account ID
Crosskix,92639
CSV
        );

        $this->artisan('crm:import-shiphero-customer-ids', [
            'file' => $csv,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $account->refresh();
        $this->assertNull($account->shiphero_customer_account_id);
    }

    private function writeTempCsv(string $contents): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'shiphero-import-'.uniqid('', true).'.csv';
        file_put_contents($path, $contents);

        return $path;
    }
}
