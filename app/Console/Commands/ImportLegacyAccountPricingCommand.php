<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\PricingFeeTemplate;
use App\Services\ClientAccountService;
use App\Services\PricingFeeTemplateService;
use App\Support\Billing\LegacyCustomerFeeImportMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Imports per-account fee amounts from legacy customers_fees (MySQL on LEGACY_DB_*)
 * into client_account_fees, matched by legacy_customer_id.
 *
 * Prerequisite: import public/customers_fees.sql into the legacy database, then:
 *   php artisan crm:import-saverack-pricing
 *   php artisan crm:import-legacy-account-pricing --dry-run
 *   php artisan crm:import-legacy-account-pricing
 */
class ImportLegacyAccountPricingCommand extends Command
{
    protected $signature = 'crm:import-legacy-account-pricing
                            {--connection=legacy_crm : Legacy MySQL connection}
                            {--fees-table=customers_fees : Legacy fees table name}
                            {--customers-table=customers : Legacy customers table for column fallback}
                            {--skip-fallback : Do not read fulfillment_fee / additional_pick_fee from customers table}
                            {--force : Overwrite non-zero account fee amounts}
                            {--dry-run : Show changes without saving}';

    protected $description = 'Import per-account pricing from legacy customers_fees into client_account_fees';

    /** @var ClientAccountService */
    private $accounts;

    /** @var PricingFeeTemplateService */
    private $templates;

    public function __construct(ClientAccountService $accounts, PricingFeeTemplateService $templates)
    {
        parent::__construct();
        $this->accounts = $accounts;
        $this->templates = $templates;
    }

    public function handle(): int
    {
        $connName = (string) $this->option('connection');
        $feesTable = trim((string) $this->option('fees-table'));
        $customersTable = trim((string) $this->option('customers-table'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $config = config("database.connections.{$connName}");
        if (! is_array($config) || empty($config['database'])) {
            $this->error("Connection [{$connName}] is not configured or LEGACY_DB_DATABASE is empty.");

            return SymfonyCommand::FAILURE;
        }

        try {
            $legacy = DB::connection($connName);
            $legacy->getPdo();
        } catch (\Throwable $e) {
            $this->error('Could not connect to legacy database: '.$e->getMessage());

            return SymfonyCommand::FAILURE;
        }

        if (! $legacy->getSchemaBuilder()->hasTable($feesTable)) {
            $this->error("Legacy table [{$feesTable}] not found. Import public/customers_fees.sql into LEGACY_DB_DATABASE first.");

            return SymfonyCommand::FAILURE;
        }

        $this->line("<fg=cyan>Legacy database:</> {$config['database']}");
        $this->line("<fg=cyan>Reading:</> `{$feesTable}` → app `client_account_fees`");

        $legacyRows = $legacy->table($feesTable)
            ->select(['customer', 'store', 'category', 'service', 'status', 'is_deleted', 'fee', 'type', 'fee_order', 'updated_at'])
            ->get()
            ->all();

        $deduped = LegacyCustomerFeeImportMapper::dedupeByCustomerAndService($legacyRows);
        $this->line('Legacy fee rows: '.count($legacyRows).' → deduped customer/service keys: '.$this->countDedupedKeys($deduped));

        $accountsByLegacyId = ClientAccount::query()
            ->whereNotNull('legacy_customer_id')
            ->get()
            ->keyBy(fn (ClientAccount $a) => (int) $a->legacy_customer_id);

        if ($accountsByLegacyId->isEmpty()) {
            $this->warn('No client_accounts with legacy_customer_id. Run crm:import-legacy-clients first.');

            return SymfonyCommand::SUCCESS;
        }

        $templateIndex = $this->buildTemplateIndex();

        $updated = 0;
        $created = 0;
        $skippedNoAccount = 0;
        $skippedUnchanged = 0;
        $skippedNoTemplate = 0;

        foreach ($deduped as $legacyCustomerId => $feeMap) {
            /** @var ClientAccount|null $account */
            $account = $accountsByLegacyId->get((int) $legacyCustomerId);
            if ($account === null) {
                $skippedNoAccount += count($feeMap);

                continue;
            }

            if (! $dryRun) {
                $this->accounts->ensureDefaultFeeItems($account);
            }

            foreach ($feeMap as $mapKey => $payload) {
                $result = $this->applyFeeToAccount(
                    $account,
                    $mapKey,
                    $payload,
                    $templateIndex,
                    $force,
                    $dryRun
                );

                if ($result === 'updated') {
                    $updated++;
                } elseif ($result === 'created') {
                    $created++;
                } elseif ($result === 'skipped_unchanged') {
                    $skippedUnchanged++;
                } else {
                    $skippedNoTemplate++;
                }
            }
        }

        if (! $this->option('skip-fallback') && $legacy->getSchemaBuilder()->hasTable($customersTable)) {
            $fallbackStats = $this->applyCustomersTableFallback(
                $legacy,
                $customersTable,
                $accountsByLegacyId,
                $templateIndex,
                $force,
                $dryRun
            );
            $updated += $fallbackStats['updated'];
            $skippedUnchanged += $fallbackStats['skipped_unchanged'];
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Accounts with legacy_customer_id', (string) $accountsByLegacyId->count()],
                ['Legacy customers with deduped fees', (string) count($deduped)],
                [$dryRun ? 'Would update fees' : 'Updated fees', (string) $updated],
                [$dryRun ? 'Would create fees' : 'Created fees', (string) $created],
                ['Skipped (unchanged)', (string) $skippedUnchanged],
                ['Skipped (no matching account)', (string) $skippedNoAccount],
                ['Skipped (unmapped service)', (string) $skippedNoTemplate],
            ]
        );

        if ($dryRun) {
            $this->info('Dry run complete — no changes saved.');
        } else {
            $this->info('Legacy account pricing import complete.');
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @param  array<int, array<string, array{service: string, fee: float, category: ?string, legacy_category: ?string}>>  $deduped
     */
    private function countDedupedKeys(array $deduped): int
    {
        $n = 0;
        foreach ($deduped as $feeMap) {
            $n += count($feeMap);
        }

        return $n;
    }

    /**
     * @return array<string, PricingFeeTemplate>
     */
    private function buildTemplateIndex(): array
    {
        $index = [];
        foreach (PricingFeeTemplate::query()->get() as $template) {
            $index[strtolower($template->category).':'.$this->normalizeName($template->name)] = $template;
        }

        return $index;
    }

    /**
     * @param  array{service: string, fee: float, category: ?string, legacy_category: ?string}  $payload
     * @param  array<string, PricingFeeTemplate>  $templateIndex
     */
    private function applyFeeToAccount(
        ClientAccount $account,
        string $mapKey,
        array $payload,
        array $templateIndex,
        bool $force,
        bool $dryRun
    ): string {
        $amount = number_format($payload['fee'], 4, '.', '');
        $service = $payload['service'];

        if (str_starts_with($mapKey, 'template:')) {
            $template = $this->findTemplateForService($service, $templateIndex);
            if ($template === null) {
                if ($dryRun) {
                    $this->warn("No template for legacy service \"{$service}\" (account {$account->id})");
                }

                return 'skipped_no_template';
            }

            return $this->upsertAccountFeeForTemplate($account, $template, $amount, $force, $dryRun);
        }

        if (str_starts_with($mapKey, 'storage:')) {
            $label = $service !== '' ? $service : 'Storage fee';
            $lineCode = 'legacy_storage_'.$this->lineSlug($label);

            return $this->upsertCustomAccountFee(
                $account,
                ClientAccountFee::GROUP_STORAGE,
                $lineCode,
                $label,
                $amount,
                $force,
                $dryRun
            );
        }

        if (str_starts_with($mapKey, 'packaging:')) {
            $label = $service !== '' ? $service : 'Packaging fee';
            $lineCode = 'legacy_packaging_'.$this->lineSlug($label);

            return $this->upsertCustomAccountFee(
                $account,
                PricingFeeTemplate::CATEGORY_PACKAGING,
                $lineCode,
                $label,
                $amount,
                $force,
                $dryRun
            );
        }

        return 'skipped_no_template';
    }

    /**
     * @param  array<string, PricingFeeTemplate>  $templateIndex
     */
    private function findTemplateForService(string $service, array $templateIndex): ?PricingFeeTemplate
    {
        $map = config('legacy_customer_fee_map', []);
        if (! isset($map[$service])) {
            return null;
        }

        $entry = $map[$service];
        $category = (string) $entry['category'];

        foreach ($entry['template_names'] as $name) {
            $key = strtolower($category).':'.$this->normalizeName($name);
            if (isset($templateIndex[$key])) {
                return $templateIndex[$key];
            }
        }

        return PricingFeeTemplate::query()
            ->where('category', $category)
            ->whereIn('name', $entry['template_names'])
            ->first();
    }

    private function upsertAccountFeeForTemplate(
        ClientAccount $account,
        PricingFeeTemplate $template,
        string $amount,
        bool $force,
        bool $dryRun
    ): string {
        $fee = ClientAccountFee::query()
            ->where('client_account_id', $account->id)
            ->where(function ($q) use ($template) {
                $q->where('pricing_template_id', $template->id);
                $lineCode = $this->lineCodeForTemplate($template);
                if ($lineCode !== null) {
                    $q->orWhere(function ($q2) use ($template, $lineCode) {
                        $q2->where('fee_group', PricingFeeTemplate::categoryToFeeGroup($template->category))
                            ->where('line_code', $lineCode);
                    });
                }
            })
            ->first();

        if ($fee === null) {
            if ($dryRun) {
                $this->line("Would create fee for account {$account->id} / {$template->name} → {$amount}");

                return 'created';
            }

            $this->templates->provisionTemplateForAccount($account, $template);
            $fee = ClientAccountFee::query()
                ->where('client_account_id', $account->id)
                ->where('pricing_template_id', $template->id)
                ->first();
        }

        if ($fee === null) {
            return 'skipped_no_template';
        }

        $current = $fee->amount !== null ? (string) $fee->amount : '0.0000';
        if (! $force && bccomp($current, '0.0000', 4) !== 0) {
            return 'skipped_unchanged';
        }
        if (bccomp($current, $amount, 4) === 0) {
            return 'skipped_unchanged';
        }

        if ($dryRun) {
            $this->line("Would update account {$account->id} / {$template->name}: {$current} → {$amount}");

            return 'updated';
        }

        $fee->update(['amount' => $amount]);

        return 'updated';
    }

    private function upsertCustomAccountFee(
        ClientAccount $account,
        string $feeGroup,
        string $lineCode,
        string $label,
        string $amount,
        bool $force,
        bool $dryRun
    ): string {
        $fee = ClientAccountFee::query()
            ->where('client_account_id', $account->id)
            ->where('fee_group', $feeGroup)
            ->where('line_code', $lineCode)
            ->first();

        if ($fee === null) {
            if ($dryRun) {
                $this->line("Would create {$feeGroup} fee \"{$label}\" for account {$account->id} → {$amount}");

                return 'created';
            }

            ClientAccountFee::query()->create([
                'client_account_id' => $account->id,
                'fee_group' => $feeGroup,
                'line_code' => $lineCode,
                'label' => $label,
                'amount' => $amount,
                'currency' => 'USD',
                'sort_order' => 100,
            ]);

            return 'created';
        }

        $current = $fee->amount !== null ? (string) $fee->amount : '0.0000';
        if (! $force && bccomp($current, $amount, 4) === 0) {
            return 'skipped_unchanged';
        }
        if (! $force && bccomp($current, '0.0000', 4) !== 0) {
            return 'skipped_unchanged';
        }

        if ($dryRun) {
            $this->line("Would update account {$account->id} / {$label}: {$current} → {$amount}");

            return 'updated';
        }

        $fee->update(['amount' => $amount, 'label' => $label]);

        return 'updated';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ClientAccount>  $accountsByLegacyId
     * @param  array<string, PricingFeeTemplate>  $templateIndex
     * @return array{updated: int, skipped_unchanged: int}
     */
    private function applyCustomersTableFallback(
        $legacy,
        string $customersTable,
        $accountsByLegacyId,
        array $templateIndex,
        bool $force,
        bool $dryRun
    ): array {
        $updated = 0;
        $skippedUnchanged = 0;

        $columns = [
            'fulfillment_fee' => 'Fulfillment',
            'additional_pick_fee' => 'Additional Picks',
            'returns_fee' => 'Returns',
            'pack_slip_fee' => 'Packing Slips',
            'inserts_fee' => 'Inserts',
            'assembly_fee' => 'Assembly or Kitting',
            'labeling_fee' => 'Labeling',
        ];

        $select = array_merge(['id'], array_keys($columns));
        $legacyCustomers = $legacy->table($customersTable)->select($select)->get();

        foreach ($legacyCustomers as $row) {
            $legacyId = (int) ($row->id ?? 0);
            $account = $accountsByLegacyId->get($legacyId);
            if ($account === null) {
                continue;
            }

            if (! $dryRun) {
                $this->accounts->ensureDefaultFeeItems($account);
            }

            foreach ($columns as $col => $service) {
                if (! isset($row->{$col})) {
                    continue;
                }
                $val = $row->{$col};
                if (! is_numeric($val) || (float) $val <= 0) {
                    continue;
                }

                $template = $this->findTemplateForService($service, $templateIndex);
                if ($template === null) {
                    continue;
                }

                $result = $this->upsertAccountFeeForTemplate(
                    $account,
                    $template,
                    number_format((float) $val, 4, '.', ''),
                    $force,
                    $dryRun
                );

                if ($result === 'updated') {
                    $updated++;
                } elseif ($result === 'skipped_unchanged') {
                    $skippedUnchanged++;
                }
            }
        }

        return ['updated' => $updated, 'skipped_unchanged' => $skippedUnchanged];
    }

    private function lineCodeForTemplate(PricingFeeTemplate $template): ?string
    {
        $map = config('legacy_customer_fee_map', []);
        foreach ($map as $entry) {
            if ((string) $entry['category'] !== $template->category) {
                continue;
            }
            if (in_array($template->name, $entry['template_names'], true)) {
                return $entry['line_code'] ?? null;
            }
        }

        return null;
    }

    private function normalizeName(string $name): string
    {
        return Str::ascii(strtolower(trim($name)));
    }

    private function lineSlug(string $value): string
    {
        $value = Str::ascii(strtolower(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;

        return trim($value, '_');
    }
}
