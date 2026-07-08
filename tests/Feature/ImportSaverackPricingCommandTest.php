<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\PricingFeeTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportSaverackPricingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_updates_legacy_first_pick_and_renames_to_canonical_name(): void
    {
        PricingFeeTemplate::query()->create([
            'name' => 'First Pick',
            'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
            'description' => 'Legacy',
            'amount' => '0.0000',
            'sort_order' => 0,
        ]);

        $this->artisan('crm:import-saverack-pricing', ['--skip-packaging' => true])
            ->assertExitCode(0);

        $template = PricingFeeTemplate::query()
            ->where('category', PricingFeeTemplate::CATEGORY_FULFILLMENT)
            ->where('name', 'Fulfillment (pick & pack 1 item)')
            ->first();

        $this->assertNotNull($template);
        $this->assertSame(1.95, (float) $template->amount);
    }

    public function test_import_creates_packaging_row_from_csv(): void
    {
        $csv = $this->writeTempCsv(<<<'CSV'
Name,Packaging Type,Price
BUBBLE MAILER #0,POLY,$0.30
CSV
        );

        $this->artisan('crm:import-saverack-pricing', [
            'file' => $csv,
            '--skip-packaging' => false,
        ])->assertExitCode(0);

        $template = PricingFeeTemplate::query()
            ->where('category', PricingFeeTemplate::CATEGORY_PACKAGING)
            ->where('name', 'BUBBLE MAILER #0')
            ->first();

        $this->assertNotNull($template);
        $this->assertSame(0.30, (float) $template->amount);
        $this->assertSame('Packaging type: POLY', $template->description);
    }

    public function test_dry_run_does_not_persist_templates(): void
    {
        $before = PricingFeeTemplate::query()->count();

        $this->artisan('crm:import-saverack-pricing', [
            '--dry-run' => true,
            '--skip-packaging' => true,
        ])->assertExitCode(0);

        $this->assertSame($before, PricingFeeTemplate::query()->count());
    }

    public function test_import_provisions_templates_to_existing_accounts(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Pricing Provision Co',
            'email' => 'pricing-provision@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);

        $this->artisan('crm:import-saverack-pricing', ['--skip-packaging' => true])
            ->assertExitCode(0);

        $this->assertGreaterThan(
            0,
            ClientAccountFee::query()->where('client_account_id', $account->id)->count()
        );
    }

    private function writeTempCsv(string $contents): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saverack-pricing-'.uniqid('', true).'.csv';
        file_put_contents($path, $contents);

        return $path;
    }
}
