<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\ClientStore;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports customers + crm_stores from a legacy MySQL CRM into client_accounts / client_stores.
 *
 * Prerequisite: create an empty MySQL database and load the dumps:
 *   mysql -u USER -p LEGACY_DB < database/customers.sql
 *   mysql -u USER -p LEGACY_DB < database/crm_stores.sql
 *
 * Then set LEGACY_DB_DATABASE (and credentials if needed) in .env and run migrations, then:
 *   php artisan crm:import-legacy-clients
 */
class ImportLegacyCrmClientsCommand extends Command
{
    protected $signature = 'crm:import-legacy-clients
                            {--connection=legacy_crm : Laravel DB connection name for legacy MySQL}
                            {--customers-table=customers : Legacy customers table name}
                            {--stores-table=crm_stores : Legacy stores table name}
                            {--skip-deleted : Omit legacy rows where is_deleted = 2}
                            {--dry-run : Show counts and sample mapping without writing}';

    protected $description = 'Import legacy CRM customers + stores from MySQL dumps into client_accounts and client_stores';

    public function handle(): int
    {
        $connName = (string) $this->option('connection');
        $customersTable = (string) $this->option('customers-table');
        $storesTable = (string) $this->option('stores-table');
        $skipDeleted = (bool) $this->option('skip-deleted');
        $dryRun = (bool) $this->option('dry-run');

        $config = config("database.connections.{$connName}");
        if (! is_array($config) || empty($config['database'])) {
            $this->error("Connection [{$connName}] is not configured or LEGACY_DB_DATABASE is empty.");

            return self::FAILURE;
        }

        if (($config['driver'] ?? '') !== 'mysql') {
            $this->error('Legacy import requires a MySQL legacy_crm connection (PDO MySQL).');

            return self::FAILURE;
        }

        try {
            DB::connection($connName)->getPdo();
        } catch (\Throwable $e) {
            $this->error('Could not connect to legacy database: '.$e->getMessage());

            return self::FAILURE;
        }

        $legacy = DB::connection($connName);

        if (! $legacy->getSchemaBuilder()->hasTable($customersTable)) {
            $this->error("Legacy table [{$customersTable}] not found. Import database/customers.sql first.");

            return self::FAILURE;
        }

        if (! $legacy->getSchemaBuilder()->hasTable($storesTable)) {
            $this->error("Legacy table [{$storesTable}] not found. Import database/crm_stores.sql first.");

            return self::FAILURE;
        }

        $customerQuery = $legacy->table($customersTable);
        if ($skipDeleted) {
            $customerQuery->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', '!=', 2);
            });
        }

        $customerTotal = (clone $customerQuery)->count();
        $this->info("Legacy customers to process: {$customerTotal}".($skipDeleted ? ' (excluding is_deleted=2)' : ' (including deleted as inactive)'));

        $storeQuery = $legacy->table($storesTable);
        if ($skipDeleted) {
            $storeQuery->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', '!=', 2);
            });
        }
        $storeTotal = (clone $storeQuery)->count();
        $this->info("Legacy stores to process: {$storeTotal}".($skipDeleted ? ' (excluding is_deleted=2)' : ''));

        if ($dryRun) {
            $this->warn('Dry run: no rows will be written.');

            return self::SUCCESS;
        }

        $importedAccounts = 0;
        $importedStores = 0;
        $skippedStoresNoAccount = 0;

        DB::beginTransaction();
        try {
            $customerQuery->orderBy('id')->chunkById(200, function ($rows) use (&$importedAccounts) {
                foreach ($rows as $row) {
                    $attrs = $this->mapCustomerRow($row);
                    ClientAccount::query()->updateOrCreate(
                        ['legacy_customer_id' => (int) $row->id],
                        $attrs
                    );
                    $importedAccounts++;
                }
            }, 'id');

            $storeQuery->orderBy('id')->chunkById(200, function ($rows) use (&$importedStores, &$skippedStoresNoAccount) {
                foreach ($rows as $row) {
                    $legacyCustomerId = $row->customer !== null ? (int) $row->customer : null;
                    if ($legacyCustomerId === null) {
                        $skippedStoresNoAccount++;

                        continue;
                    }

                    $accountId = ClientAccount::query()
                        ->where('legacy_customer_id', $legacyCustomerId)
                        ->value('id');

                    if ($accountId === null) {
                        $skippedStoresNoAccount++;

                        continue;
                    }

                    $attrs = $this->mapStoreRow($row, (int) $accountId);
                    ClientStore::query()->updateOrCreate(
                        ['legacy_store_id' => (int) $row->id],
                        $attrs
                    );
                    $importedStores++;
                }
            }, 'id');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported/updated client_accounts: {$importedAccounts}");
        $this->info("Imported/updated client_stores: {$importedStores}");
        if ($skippedStoresNoAccount > 0) {
            $this->warn("Skipped stores (missing legacy customer id or unknown customer): {$skippedStoresNoAccount}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  object  $row  stdClass from legacy query
     */
    private function mapCustomerRow(object $row): array
    {
        $isDeleted = isset($row->is_deleted) ? (int) $row->is_deleted : 1;
        $legacyStatus = isset($row->status) ? (int) $row->status : 1;

        $status = ClientAccount::STATUS_ACTIVE;
        if ($isDeleted === 2) {
            $status = ClientAccount::STATUS_INACTIVE;
        } elseif ($legacyStatus === 1) {
            $status = ClientAccount::STATUS_PENDING;
        } elseif ($legacyStatus === 2 || $legacyStatus === 3) {
            $status = ClientAccount::STATUS_ACTIVE;
        }

        $email = $this->truncate($this->normEmail($row->c_email ?? ''), 190);
        if ($email === '') {
            $email = 'legacy-customer-'.(int) $row->id.'@import.invalid';
        }

        [$first, $last] = $this->splitPersonName((string) ($row->c_name ?? ''));

        $street = $this->buildStreet($row, 'b');
        if ($street === '') {
            $street = $this->buildStreet($row, 'c');
        }

        $city = $this->nonEmptyString($row->b_city ?? null) ?? $this->nonEmptyString($row->c_city ?? null);
        $state = $this->nonEmptyString($row->b_state ?? null) ?? $this->nonEmptyString($row->c_state ?? null);
        $zip = $this->nonEmptyString($row->b_zip ?? null) ?? $this->nonEmptyString($row->c_zip ?? null);
        $country = $this->nonEmptyString($row->b_country ?? null) ?? $this->nonEmptyString($row->c_country ?? null);

        $phone = $this->nonEmptyString($row->c_phone ?? null) ?? $this->nonEmptyString($row->b_phone ?? null);

        $telegram = $this->nonEmptyString($row->c_telegram ?? null)
            ?? $this->nonEmptyString($row->b_telegram ?? null);

        $website = $this->nonEmptyString($row->website_url ?? null);
        $website = $this->truncate($website ?? '', 512);

        $brand = $this->nonEmptyString($row->b_name ?? null);
        $brand = $brand !== null ? $this->truncate($brand, 190) : null;

        $created = $this->parseTimestamp($row->created_at ?? null);
        $updated = $this->parseTimestamp($row->updated_at ?? null);

        return array_filter([
            'status' => $status,
            'company_name' => $this->truncate((string) ($row->company_name ?? 'Legacy import'), 190),
            'brand_name' => $brand,
            'website' => $website !== '' ? $website : null,
            'contact_first_name' => $first !== '' ? $this->truncate($first, 100) : null,
            'contact_last_name' => $last !== '' ? $this->truncate($last, 100) : null,
            'email' => $email,
            'phone' => $phone !== null ? $this->truncate($phone, 64) : null,
            'notify_email' => true,
            'telegram_handle' => $telegram !== null ? $this->truncate(ltrim($telegram, '@'), 190) : null,
            'street' => $street !== '' ? $this->truncate($street, 190) : null,
            'city' => $city !== null ? $this->truncate($city, 120) : null,
            'state' => $state !== null ? $this->truncate($state, 64) : null,
            'zip' => $zip !== null ? $this->truncate($zip, 32) : null,
            'country' => $country !== null ? $this->truncate($country, 120) : null,
            'account_manager_id' => null,
            'created_at' => $created,
            'updated_at' => $updated,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param  object  $row
     */
    private function mapStoreRow(object $row, int $clientAccountId): array
    {
        $isDeleted = isset($row->is_deleted) ? (int) $row->is_deleted : 1;
        $shipStatus = isset($row->ship_status) ? (int) $row->ship_status : 2;

        $status = ClientStore::STATUS_INACTIVE;
        if ($isDeleted !== 2 && $shipStatus === 1) {
            $status = ClientStore::STATUS_ACTIVE;
        } elseif ($isDeleted !== 2 && $shipStatus !== 1) {
            $status = ClientStore::STATUS_INACTIVE;
        }

        $num = isset($row->store_number) ? trim((string) $row->store_number) : '';
        $baseName = trim((string) ($row->store_name ?? 'Store'));
        $name = $baseName;
        if ($num !== '' && $num !== '0') {
            $suffix = ' (#'.str_replace(["\r", "\n"], '', $num).')';
            $name = $this->truncate($baseName.$suffix, 190);
        } else {
            $name = $this->truncate($baseName, 190);
        }

        $websiteRaw = $this->nonEmptyString($row->store_url ?? null);
        $website = $websiteRaw !== null ? $this->truncate($websiteRaw, 512) : null;

        $market = $this->nonEmptyString($row->sell_platform ?? null)
            ?? $this->nonEmptyString($row->market_place ?? null);
        $market = $market !== null ? $this->truncate($market, 190) : null;

        $created = $this->parseTimestamp($row->created_at ?? null);
        $updated = $this->parseTimestamp($row->updated_at ?? null);

        return array_filter([
            'client_account_id' => $clientAccountId,
            'status' => $status,
            'name' => $name,
            'website' => $website,
            'marketplace' => $market,
            'created_at' => $created,
            'updated_at' => $updated,
        ], static fn ($v) => $v !== null);
    }

    private function buildStreet(object $row, string $prefix): string
    {
        $a1 = $this->nonEmptyString($row->{$prefix.'_address_1'} ?? null) ?? '';
        $a2 = $this->nonEmptyString($row->{$prefix.'_address_2'} ?? null) ?? '';
        $parts = array_filter([$a1, $a2]);

        return trim(implode(', ', $parts));
    }

    private function splitPersonName(string $full): array
    {
        $full = trim(preg_replace('/\s+/u', ' ', $full) ?? '');
        if ($full === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/u', $full, 2);

        return [
            (string) ($parts[0] ?? ''),
            (string) ($parts[1] ?? ''),
        ];
    }

    private function normEmail(?string $e): string
    {
        if ($e === null) {
            return '';
        }

        return trim(strtolower($e));
    }

    private function nonEmptyString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max);
    }

    private function parseTimestamp(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }
}
