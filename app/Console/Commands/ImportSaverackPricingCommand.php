<?php

namespace App\Console\Commands;

use App\Models\PricingFeeTemplate;
use App\Services\PricingFeeTemplateService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Import Save Rack standard pricing into pricing_fee_templates from config + packaging CSV.
 */
class ImportSaverackPricingCommand extends Command
{
    protected $signature = 'crm:import-saverack-pricing
                            {file? : Path to packaging CSV (default: public/Save Rack Fulfillment Fees - Packaging Pricing.csv)}
                            {--force : Overwrite non-zero template amounts}
                            {--dry-run : Show changes without saving}
                            {--skip-packaging : Import catalog only, skip packaging CSV}';

    protected $description = 'Import Save Rack standard pricing (catalog + packaging CSV) into Settings pricing templates';

    /** @var PricingFeeTemplateService */
    private $templates;

    public function __construct(PricingFeeTemplateService $templates)
    {
        parent::__construct();
        $this->templates = $templates;
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $rows = $this->catalogRows();
        if (! $this->option('skip-packaging')) {
            $csvPath = $this->resolvePackagingCsvPath();
            if ($csvPath === null) {
                return SymfonyCommand::FAILURE;
            }
            $rows = array_merge($rows, $this->packagingRowsFromCsv($csvPath));
        }

        if ($rows === []) {
            $this->warn('No pricing rows to import.');

            return SymfonyCommand::SUCCESS;
        }

        $rows = $this->assignSortOrders($rows);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        /** @var list<int> $touchedTemplateIds */
        $touchedTemplateIds = [];

        foreach ($rows as $row) {
            $result = $this->upsertTemplate($row, $force, $dryRun, $touchedTemplateIds);
            if ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            } else {
                $skipped++;
            }
        }

        if (! $dryRun && $touchedTemplateIds !== []) {
            $templates = PricingFeeTemplate::query()->whereIn('id', $touchedTemplateIds)->get();
            foreach ($templates as $template) {
                $this->templates->provisionTemplateToAllAccounts($template);
            }
        }

        $provisionCount = $dryRun ? $created + $updated : count($touchedTemplateIds);

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total rows', (string) count($rows)],
                [$dryRun ? 'Would create' : 'Created', (string) $created],
                [$dryRun ? 'Would update' : 'Updated', (string) $updated],
                ['Skipped (unchanged)', (string) $skipped],
                [$dryRun ? 'Would provision templates' : 'Provisioned templates', (string) $provisionCount],
            ]
        );

        if ($dryRun) {
            $this->info('Dry run complete — no changes saved.');
        } else {
            $this->info('Save Rack pricing import complete.');
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @return list<array{name: string, category: string, amount: float, description: string, aliases: list<string>}>
     */
    private function catalogRows(): array
    {
        /** @var list<array<string, mixed>> $catalog */
        $catalog = config('saverack_pricing_catalog', []);

        $rows = [];
        foreach ($catalog as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $category = trim((string) ($item['category'] ?? ''));
            if ($name === '' || $category === '') {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'category' => $category,
                'amount' => $this->parseAmount($item['amount'] ?? 0),
                'description' => trim((string) ($item['description'] ?? '')),
                'aliases' => array_values(array_filter(array_map(
                    fn ($a) => trim((string) $a),
                    (array) ($item['aliases'] ?? [])
                ))),
            ];
        }

        return $rows;
    }

    private function resolvePackagingCsvPath(): ?string
    {
        $arg = trim((string) $this->argument('file'));
        if ($arg !== '') {
            $path = $this->resolvePath($arg);
            if (! is_file($path)) {
                $this->error("File not found: {$arg}");

                return null;
            }
            $this->line("<fg=cyan>Packaging CSV:</> {$path}");

            return $path;
        }

        $publicDir = public_path();
        $patterns = [
            $publicDir.DIRECTORY_SEPARATOR.'Save Rack Fulfillment Fees - Packaging Pricing.csv',
            ... (glob($publicDir.DIRECTORY_SEPARATOR.'*Packaging*Pricing*.csv') ?: []),
        ];

        $candidates = array_values(array_filter($patterns, 'is_file'));
        if ($candidates === []) {
            $this->error('No packaging CSV found. Pass a file path or place CSV in public/.');
            $this->line('Expected: public/Save Rack Fulfillment Fees - Packaging Pricing.csv');

            return null;
        }

        usort($candidates, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));
        $path = $candidates[0];
        $this->line("<fg=cyan>Packaging CSV:</> {$path}");

        return $path;
    }

    private function resolvePath(string $path): string
    {
        if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @return list<array{name: string, category: string, amount: float, description: string, aliases: list<string>}>
     */
    private function packagingRowsFromCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            $this->error("Could not open CSV: {$filePath}");

            return [];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $this->error('Packaging CSV is empty.');

            return [];
        }

        $header = array_map(fn ($h) => trim((string) $h), $header);
        $nameIdx = array_search('Name', $header, true);
        $typeIdx = array_search('Packaging Type', $header, true);
        $priceIdx = array_search('Price', $header, true);

        if ($nameIdx === false || $priceIdx === false) {
            fclose($handle);
            $this->error('Packaging CSV must have Name and Price columns. Found: '.implode(', ', $header));

            return [];
        }

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }

            $name = trim((string) ($data[$nameIdx] ?? ''));
            if ($name === '') {
                continue;
            }

            $amount = $this->parseAmount((string) ($data[$priceIdx] ?? ''));
            $packagingType = $typeIdx !== false ? trim((string) ($data[$typeIdx] ?? '')) : '';
            $description = $packagingType !== '' ? "Packaging type: {$packagingType}" : '';

            $rows[] = [
                'name' => $name,
                'category' => PricingFeeTemplate::CATEGORY_PACKAGING,
                'amount' => $amount,
                'description' => $description,
                'aliases' => [],
            ];
        }

        fclose($handle);
        $this->line('Packaging CSV rows: '.count($rows));

        return $rows;
    }

    /**
     * @param  list<array{name: string, category: string, amount: float, description: string, aliases: list<string>}>  $rows
     * @return list<array{name: string, category: string, amount: float, description: string, aliases: list<string>, sort_order: int}>
     */
    private function assignSortOrders(array $rows): array
    {
        $orderByCategory = [];
        $result = [];

        foreach ($rows as $row) {
            $cat = $row['category'];
            $orderByCategory[$cat] = ($orderByCategory[$cat] ?? 0);
            $row['sort_order'] = $orderByCategory[$cat];
            $orderByCategory[$cat]++;
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  array{name: string, category: string, amount: float, description: string, aliases: list<string>, sort_order: int}  $row
     * @param  list<int>  $touchedTemplateIds
     */
    private function upsertTemplate(array $row, bool $force, bool $dryRun, array &$touchedTemplateIds): string
    {
        $template = $this->findExistingTemplate($row);
        $renameToCanonical = $template !== null
            && $template->name !== $row['name']
            && in_array($template->name, $row['aliases'], true);

        if ($template === null) {
            if ($dryRun) {
                $this->line("Would create [{$row['category']}] {$row['name']} → {$row['amount']}");

                return 'created';
            }

            $created = PricingFeeTemplate::query()->create([
                'name' => $row['name'],
                'category' => $row['category'],
                'description' => $row['description'] !== '' ? $row['description'] : null,
                'amount' => $this->formatAmount($row['amount']),
                'sort_order' => $row['sort_order'],
            ]);
            $touchedTemplateIds[] = (int) $created->id;
            $this->info("Created [{$row['category']}] {$row['name']} → {$row['amount']}");

            return 'created';
        }

        $currentAmount = (float) $template->amount;
        $shouldUpdateAmount = $force || abs($currentAmount) < 0.00001;
        $descriptionChanged = ($template->description ?? '') !== $row['description'];

        if (! $shouldUpdateAmount && ! $descriptionChanged && ! $renameToCanonical) {
            return 'skipped';
        }

        if ($dryRun) {
            $parts = [];
            if ($shouldUpdateAmount && abs($currentAmount - $row['amount']) >= 0.00001) {
                $parts[] = "amount {$currentAmount} → {$row['amount']}";
            }
            if ($renameToCanonical) {
                $parts[] = "name \"{$template->name}\" → \"{$row['name']}\"";
            }
            $label = $renameToCanonical ? $row['name'] : $template->name;
            $this->line('Would update ['.$row['category'].'] '.$label.($parts !== [] ? ': '.implode(', ', $parts) : ''));

            return 'updated';
        }

        $updates = ['sort_order' => $row['sort_order']];
        if ($shouldUpdateAmount) {
            $updates['amount'] = $this->formatAmount($row['amount']);
        }
        if ($row['description'] !== '') {
            $updates['description'] = $row['description'];
        }
        if ($renameToCanonical) {
            $updates['name'] = $row['name'];
        }

        $template->update($updates);
        $fresh = $template->fresh();
        if ($fresh !== null) {
            $this->templates->syncLinkedAccountFees($fresh);
            $touchedTemplateIds[] = (int) $fresh->id;
        }
        $this->info("Updated [{$row['category']}] {$row['name']} → {$row['amount']}");

        return 'updated';
    }

    /**
     * @param  array{name: string, category: string, aliases: list<string>}  $row
     */
    private function findExistingTemplate(array $row): ?PricingFeeTemplate
    {
        $template = PricingFeeTemplate::query()
            ->where('category', $row['category'])
            ->where('name', $row['name'])
            ->first();

        if ($template !== null) {
            return $template;
        }

        foreach ($row['aliases'] as $alias) {
            $template = PricingFeeTemplate::query()
                ->where('category', $row['category'])
                ->where('name', $alias)
                ->first();
            if ($template !== null) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $value
     */
    private function parseAmount($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $s = trim((string) $value);
        $s = str_replace(['$', ','], '', $s);
        if ($s === '' || ! is_numeric($s)) {
            return 0.0;
        }

        return (float) $s;
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 4, '.', '');
    }
}
