<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\ClientStore;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reads legacy MySQL source tables (`customers`, `crm_stores` from your dumps) and writes this app’s
 * `client_accounts` / `client_stores` on the default DB connection.
 *
 * The .sql files are NOT read by this command — load them into MySQL first, then set LEGACY_DB_DATABASE.
 */
class ImportLegacyCrmClientsCommand extends Command
{
    protected $signature = 'crm:import-legacy-clients
                            {--connection=legacy_crm : Laravel DB connection name for legacy MySQL}
                            {--customers-table=auto : Source accounts table: auto prefers customers, or pass a table name}
                            {--stores-table=auto : Source stores table: auto prefers crm_stores, or pass a table name}
                            {--skip-deleted : Omit legacy rows where is_deleted = 2 (when that column exists)}
                            {--dry-run : Show counts and sample mapping without writing}';

    protected $description = 'Import legacy customers + crm_stores from MySQL into client_accounts/client_stores';

    /** @var bool */
    protected $legacyAccountsOldCrmColumns = false;

    /** @var string */
    protected $legacyStoreAccountFkColumn = 'customer';

    /** @var bool */
    protected $legacyStoresOldCrmColumns = false;

    public function handle(): int
    {
        $connName = (string) $this->option('connection');
        $customersTableOpt = (string) $this->option('customers-table');
        $storesTableOpt = (string) $this->option('stores-table');
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
        $schema = $legacy->getSchemaBuilder();

        try {
            $customersTable = $this->resolveAccountsSourceTable($schema, $customersTableOpt);
            $storesTable = $this->resolveStoresSourceTable($schema, $storesTableOpt);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $schema->hasTable($customersTable) || ! $schema->hasTable($storesTable)) {
            $this->error('Resolved legacy tables missing (internal error).');

            return self::FAILURE;
        }

        $this->line("<fg=cyan>Legacy connection database:</> {$config['database']}");
        $this->line("<fg=cyan>Reading source tables:</> `{$customersTable}` → app `client_accounts`, `{$storesTable}` → app `client_stores`");

        if ($this->legacyMysqlMatchesAppDatabase($connName)) {
            $this->warn('LEGACY_DB_DATABASE is the same as your app MySQL database. That is OK only if you imported `customers.sql` / `crm_stores.sql` into this database so `customers` and `crm_stores` exist with full data. If you did not import those dumps, the importer will only see your current Laravel tables.');
        }

        $nCustomersTable = (int) $legacy->table($customersTable)->count();
        $nCustomersDump = $schema->hasTable('customers') ? (int) $legacy->table('customers')->count() : 0;

        if ($customersTable === 'client_accounts' && $nCustomersTable < 50 && $schema->hasTable('customers') && $nCustomersDump > $nCustomersTable) {
            $this->error('You are reading `client_accounts` ('.$nCustomersTable.' rows) but `customers` exists with '.$nCustomersDump.' rows. Re-run with --customers-table=customers --stores-table=crm_stores, or use --customers-table=auto (default).');

            return self::FAILURE;
        }

        if ($customersTable === 'client_accounts' && $nCustomersTable < 50 && ! $schema->hasTable('customers')) {
            $this->warn('Only '.$nCustomersTable.' rows in `'.$customersTable.'`. If you expected hundreds, create a MySQL database, run: mysql ... < database/customers.sql && mysql ... < database/crm_stores.sql — then set LEGACY_DB_DATABASE to that database.');
        }

        $nStoresTable = (int) $legacy->table($storesTable)->count();
        $nCrmStoresDump = $schema->hasTable('crm_stores') ? (int) $legacy->table('crm_stores')->count() : 0;
        if ($storesTable === 'client_stores' && $nStoresTable < 50 && $schema->hasTable('crm_stores') && $nCrmStoresDump > $nStoresTable) {
            $this->error('You are reading `client_stores` ('.$nStoresTable.' rows) but `crm_stores` exists with '.$nCrmStoresDump.' rows. Use --stores-table=auto (default) or --stores-table=crm_stores.');

            return self::FAILURE;
        }

        $this->legacyAccountsOldCrmColumns = $schema->hasColumn($customersTable, 'c_email');
        if ($schema->hasColumn($storesTable, 'customer')) {
            $this->legacyStoreAccountFkColumn = 'customer';
        } elseif ($schema->hasColumn($storesTable, 'client_account_id')) {
            $this->legacyStoreAccountFkColumn = 'client_account_id';
        } else {
            $this->error("Legacy stores table [{$storesTable}] needs a `customer` or `client_account_id` column linking to accounts.");

            return self::FAILURE;
        }

        $this->legacyStoresOldCrmColumns = $schema->hasColumn($storesTable, 'store_name');

        $accountsHaveIsDeleted = $schema->hasColumn($customersTable, 'is_deleted');
        $storesHaveIsDeleted = $schema->hasColumn($storesTable, 'is_deleted');

        $customerQuery = $legacy->table($customersTable);
        if ($skipDeleted && $accountsHaveIsDeleted) {
            $customerQuery->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', '!=', 2);
            });
        }

        $customerTotal = (clone $customerQuery)->count();
        $this->info("Legacy customers to process: {$customerTotal}".($skipDeleted ? ' (excluding is_deleted=2)' : ' (including deleted as inactive)'));

        $storeQuery = $legacy->table($storesTable);
        if ($skipDeleted && $storesHaveIsDeleted) {
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
                $fk = $this->legacyStoreAccountFkColumn;
                foreach ($rows as $row) {
                    $rawFk = $row->{$fk} ?? null;
                    $legacyCustomerId = $rawFk !== null ? (int) $rawFk : null;
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
        if ($this->legacyAccountsOldCrmColumns) {
            return $this->mapCustomerRowOldCrm($row);
        }

        return $this->mapCustomerRowLaravelAccounts($row);
    }

    /**
     * Old phpMyAdmin `customers` table (c_email, c_name, b_*, website_url, numeric status, …).
     *
     * @param  object  $row
     */
    private function mapCustomerRowOldCrm(object $row): array
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

        $stripeCustomerId = $this->nullableTruncate($row->stripe_customer_id ?? null, 191);
        $whatsappApiId = $this->nullableTruncate($row->wp_chat_id ?? null, 191);

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
            'stripe_customer_id' => $stripeCustomerId,
            'whatsapp_api_id' => $whatsappApiId,
            'street' => $street !== '' ? $this->truncate($street, 190) : null,
            'city' => $city !== null ? $this->truncate($city, 120) : null,
            'state' => $state !== null ? $this->truncate($state, 64) : null,
            'zip' => $zip !== null ? $this->truncate($zip, 32) : null,
            'country' => $country !== null ? $this->truncate($country, 120) : null,
            'account_manager_id' => null,
            'created_at' => $created,
            'updated_at' => $updated,
        ], function ($v) {
            return $v !== null;
        });
    }

    /**
     * Laravel-style `client_accounts` (email, string status, profile columns).
     *
     * @param  object  $row
     */
    private function mapCustomerRowLaravelAccounts(object $row): array
    {
        $status = (string) ($row->status ?? ClientAccount::STATUS_PENDING);
        if (! in_array($status, ClientAccount::STATUSES, true)) {
            $status = ClientAccount::STATUS_PENDING;
        }

        if (isset($row->is_deleted) && (int) $row->is_deleted === 2) {
            $status = ClientAccount::STATUS_INACTIVE;
        }

        $email = $this->truncate($this->normEmail($row->email ?? ''), 190);
        if ($email === '') {
            $email = 'legacy-customer-'.(int) $row->id.'@import.invalid';
        }

        $website = $this->nonEmptyString($row->website ?? null);
        $website = $website !== null ? $this->truncate($website, 512) : null;

        $telegram = $this->nonEmptyString($row->telegram_handle ?? null);

        $created = $this->parseTimestamp($row->created_at ?? null);
        $updated = $this->parseTimestamp($row->updated_at ?? null);

        $managerId = isset($row->account_manager_id) ? $row->account_manager_id : null;
        $managerId = $managerId !== null && $managerId !== '' ? (int) $managerId : null;
        if ($managerId === 0) {
            $managerId = null;
        }

        return array_filter([
            'status' => $status,
            'company_name' => $this->truncate((string) ($row->company_name ?? 'Legacy import'), 190),
            'brand_name' => $this->nullableTruncate($row->brand_name ?? null, 190),
            'website' => $website,
            'contact_first_name' => $this->nullableTruncate($row->contact_first_name ?? null, 100),
            'contact_last_name' => $this->nullableTruncate($row->contact_last_name ?? null, 100),
            'email' => $email,
            'phone' => $this->nullableTruncate($row->phone ?? null, 64),
            'notify_email' => isset($row->notify_email) ? (bool) $row->notify_email : true,
            'telegram_handle' => $telegram !== null ? $this->truncate(ltrim($telegram, '@'), 190) : null,
            'whatsapp_e164' => $this->nullableTruncate($row->whatsapp_e164 ?? null, 65535),
            'street' => $this->nullableTruncate($row->street ?? null, 190),
            'city' => $this->nullableTruncate($row->city ?? null, 120),
            'state' => $this->nullableTruncate($row->state ?? null, 64),
            'zip' => $this->nullableTruncate($row->zip ?? null, 32),
            'country' => $this->nullableTruncate($row->country ?? null, 120),
            'account_manager_id' => $managerId,
            'created_at' => $created,
            'updated_at' => $updated,
        ], function ($v) {
            return $v !== null;
        });
    }

    /**
     * @param  mixed  $v
     */
    private function nullableTruncate($v, int $max): ?string
    {
        $s = $this->nonEmptyString($v);

        return $s !== null ? $this->truncate($s, $max) : null;
    }

    /**
     * @param  object  $row
     */
    private function mapStoreRow(object $row, int $clientAccountId): array
    {
        if ($this->legacyStoresOldCrmColumns) {
            return $this->mapStoreRowOldCrm($row, $clientAccountId);
        }

        return $this->mapStoreRowLaravelStores($row, $clientAccountId);
    }

    /**
     * Old `crm_stores` table (store_name, ship_status, store_url, …).
     *
     * @param  object  $row
     */
    private function mapStoreRowOldCrm(object $row, int $clientAccountId): array
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
        ], function ($v) {
            return $v !== null;
        });
    }

    /**
     * Laravel-style `client_stores` (name, string status, marketplace).
     *
     * @param  object  $row
     */
    private function mapStoreRowLaravelStores(object $row, int $clientAccountId): array
    {
        $status = (string) ($row->status ?? ClientStore::STATUS_PENDING);
        if (! in_array($status, ClientStore::STATUSES, true)) {
            $status = ClientStore::STATUS_PENDING;
        }

        if (isset($row->is_deleted) && (int) $row->is_deleted === 2) {
            $status = ClientStore::STATUS_INACTIVE;
        }

        $name = $this->truncate(trim((string) ($row->name ?? 'Store')), 190);

        $website = $this->nullableTruncate($row->website ?? null, 512);
        $market = $this->nullableTruncate($row->marketplace ?? null, 190);

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
        ], function ($v) {
            return $v !== null;
        });
    }

    /**
     * @param  \Illuminate\Database\Schema\Builder  $schema
     */
    private function resolveAccountsSourceTable($schema, string $explicit): string
    {
        $explicit = trim($explicit);
        if ($explicit !== '' && strcasecmp($explicit, 'auto') !== 0) {
            if (! $schema->hasTable($explicit)) {
                throw new \InvalidArgumentException("Legacy accounts table [{$explicit}] does not exist.");
            }

            return $explicit;
        }
        if ($schema->hasTable('customers')) {
            return 'customers';
        }
        if ($schema->hasTable('client_accounts')) {
            return 'client_accounts';
        }

        throw new \InvalidArgumentException(
            'No source accounts table found. Import `database/customers.sql` into MySQL (creates `customers`), then set LEGACY_DB_DATABASE to that database.'
        );
    }

    /**
     * @param  \Illuminate\Database\Schema\Builder  $schema
     */
    private function resolveStoresSourceTable($schema, string $explicit): string
    {
        $explicit = trim($explicit);
        if ($explicit !== '' && strcasecmp($explicit, 'auto') !== 0) {
            if (! $schema->hasTable($explicit)) {
                throw new \InvalidArgumentException("Legacy stores table [{$explicit}] does not exist.");
            }

            return $explicit;
        }
        if ($schema->hasTable('crm_stores')) {
            return 'crm_stores';
        }
        if ($schema->hasTable('client_stores')) {
            return 'client_stores';
        }

        throw new \InvalidArgumentException(
            'No source stores table found. Import `database/crm_stores.sql` into MySQL (creates `crm_stores`), then set LEGACY_DB_DATABASE to that database.'
        );
    }

    private function legacyMysqlMatchesAppDatabase(string $legacyConnName): bool
    {
        $defaultName = (string) config('database.default');
        $appCfg = config("database.connections.{$defaultName}");
        $legCfg = config("database.connections.{$legacyConnName}");
        if (! is_array($appCfg) || ! is_array($legCfg)) {
            return false;
        }
        if (($appCfg['driver'] ?? '') !== 'mysql' || ($legCfg['driver'] ?? '') !== 'mysql') {
            return false;
        }
        $sameDb = (string) ($appCfg['database'] ?? '') === (string) ($legCfg['database'] ?? '');
        $sameHost = (string) ($appCfg['host'] ?? '') === (string) ($legCfg['host'] ?? '');
        $samePort = (string) ($appCfg['port'] ?? '3306') === (string) ($legCfg['port'] ?? '3306');

        return $sameDb && $sameHost && $samePort;
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

    /**
     * @param  mixed  $v
     */
    private function nonEmptyString($v): ?string
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

    /**
     * @param  mixed  $v
     */
    private function parseTimestamp($v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
