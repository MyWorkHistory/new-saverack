<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Import ShipHero customer account IDs from a CSV export (Name + Customer Account ID columns)
 * into client_accounts.shiphero_customer_account_id, matched by company_name or brand_name.
 */
class ImportShipHeroCustomerAccountIdsCommand extends Command
{
    protected $signature = 'crm:import-shiphero-customer-ids
                            {file? : Path to CSV (defaults to newest customers_table*.csv in public/)}
                            {--name-column=Name : CSV column for client name}
                            {--id-column=Customer Account ID : CSV column for ShipHero customer account id}
                            {--force : Overwrite non-empty shiphero_customer_account_id values}
                            {--dry-run : Show matches without saving}';

    protected $description = 'Import ShipHero customer account IDs from CSV into client_accounts (match by company/brand name)';

    public function handle(): int
    {
        $filePath = $this->resolveCsvPath();
        if ($filePath === null) {
            return SymfonyCommand::FAILURE;
        }

        $nameColumn = trim((string) $this->option('name-column'));
        $idColumn = trim((string) $this->option('id-column'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $rows = $this->readCsvRows($filePath, $nameColumn, $idColumn);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return SymfonyCommand::FAILURE;
        }

        if ($rows === []) {
            $this->warn('No data rows found in CSV.');

            return SymfonyCommand::SUCCESS;
        }

        $accountsByName = $this->buildAccountLookupIndex();

        $updated = 0;
        $skippedExisting = 0;
        $skippedConflict = 0;
        $unmatched = [];

        foreach ($rows as $row) {
            $csvName = $row['name'];
            $shipheroId = $row['id'];
            $normalized = self::normalizeAccountName($csvName);

            if ($normalized === '') {
                continue;
            }

            $matches = $accountsByName[$normalized] ?? [];
            if ($matches === []) {
                $unmatched[] = ['csv_name' => $csvName, 'shiphero_id' => $shipheroId];

                continue;
            }

            if (count($matches) > 1) {
                $skippedConflict++;
                $ids = implode(', ', array_map(fn (ClientAccount $a) => (string) $a->id, $matches));
                $this->warn("Ambiguous name \"{$csvName}\" → client_accounts [{$ids}] (skipped)");

                continue;
            }

            /** @var ClientAccount $account */
            $account = $matches[0];
            $current = trim((string) ($account->shiphero_customer_account_id ?? ''));

            if ($current !== '' && $current === $shipheroId) {
                continue;
            }

            if ($current !== '' && ! $force) {
                $skippedExisting++;
                $this->line("Skip id={$account->id} \"{$account->company_name}\": already {$current} (use --force to replace with {$shipheroId})");

                continue;
            }

            if ($dryRun) {
                $this->line("Would set id={$account->id} \"{$account->company_name}\" → {$shipheroId} (csv: \"{$csvName}\")");
                $updated++;

                continue;
            }

            $account->update(['shiphero_customer_account_id' => $shipheroId]);
            $this->info("Updated id={$account->id} \"{$account->company_name}\" → {$shipheroId}");
            $updated++;
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['CSV rows', (string) count($rows)],
                [$dryRun ? 'Would update' : 'Updated', (string) $updated],
                ['Skipped (existing id, no --force)', (string) $skippedExisting],
                ['Skipped (ambiguous name)', (string) $skippedConflict],
                ['Unmatched CSV names', (string) count($unmatched)],
            ]
        );

        if ($unmatched !== []) {
            $this->newLine();
            $this->warn('Unmatched CSV names (no client_accounts.company_name / brand_name match):');
            $preview = array_slice($unmatched, 0, 25);
            $this->table(
                ['CSV name', 'ShipHero customer account id'],
                array_map(fn (array $u) => [$u['csv_name'], $u['shiphero_id']], $preview)
            );
            if (count($unmatched) > 25) {
                $this->line('… and '.(count($unmatched) - 25).' more');
            }
        }

        return SymfonyCommand::SUCCESS;
    }

    private function resolveCsvPath(): ?string
    {
        $arg = trim((string) $this->argument('file'));
        if ($arg !== '') {
            $path = $this->resolvePath($arg);
            if (! is_file($path)) {
                $this->error("File not found: {$arg}");

                return null;
            }

            $this->line("<fg=cyan>CSV file:</> {$path}");

            return $path;
        }

        $publicDir = public_path();
        $candidates = glob($publicDir.DIRECTORY_SEPARATOR.'customers_table*.csv') ?: [];
        if ($candidates === []) {
            $this->error('No CSV path given and no public/customers_table*.csv found.');
            $this->line('Usage: php artisan crm:import-shiphero-customer-ids /path/to/export.csv');

            return null;
        }

        usort($candidates, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));
        $path = $candidates[0];
        $this->line('<fg=cyan>CSV file (newest customers_table*.csv):</> '.$path);

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
     * @return list<array{name: string, id: string}>
     */
    private function readCsvRows(string $filePath, string $nameColumn, string $idColumn): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException("Could not open CSV: {$filePath}");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new \InvalidArgumentException('CSV is empty or unreadable.');
        }

        $header = array_map(fn ($h) => trim((string) $h), $header);
        $nameIdx = array_search($nameColumn, $header, true);
        $idIdx = array_search($idColumn, $header, true);

        if ($nameIdx === false) {
            fclose($handle);
            throw new \InvalidArgumentException("Name column \"{$nameColumn}\" not found. Headers: ".implode(', ', $header));
        }

        if ($idIdx === false) {
            fclose($handle);
            throw new \InvalidArgumentException("ID column \"{$idColumn}\" not found. Headers: ".implode(', ', $header));
        }

        $rows = [];
        $seenNames = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }

            $name = trim((string) ($data[$nameIdx] ?? ''));
            $id = trim((string) ($data[$idIdx] ?? ''));
            if ($name === '' || $id === '') {
                continue;
            }

            if (! ctype_digit($id)) {
                continue;
            }

            $normalized = self::normalizeAccountName($name);
            if ($normalized === '') {
                continue;
            }

            if (isset($seenNames[$normalized]) && $seenNames[$normalized] !== $id) {
                $this->warn("Duplicate CSV name \"{$name}\" with different IDs ({$seenNames[$normalized]} vs {$id}); keeping first.");
                continue;
            }

            $seenNames[$normalized] = $id;
            $rows[] = ['name' => $name, 'id' => $id];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array<string, list<ClientAccount>>
     */
    private function buildAccountLookupIndex(): array
    {
        $index = [];

        ClientAccount::query()
            ->select(['id', 'company_name', 'brand_name', 'shiphero_customer_account_id'])
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$index) {
                foreach ($accounts as $account) {
                    foreach ([$account->company_name, $account->brand_name] as $label) {
                        $normalized = self::normalizeAccountName((string) $label);
                        if ($normalized === '') {
                            continue;
                        }
                        $index[$normalized][] = $account;
                    }
                }
            });

        foreach ($index as $key => $accounts) {
            $index[$key] = collect($accounts)->unique('id')->values()->all();
        }

        return $index;
    }

    public static function normalizeAccountName(string $name): string
    {
        $name = Str::ascii(trim($name));
        $name = strtolower($name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name) ?? $name;

        return trim($name);
    }
}
