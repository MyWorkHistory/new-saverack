<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Support\Legacy\LegacyCustomerAccountImportMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Syncs account profile/billing enrichment fields from legacy `customers` into `client_accounts`
 * matched by {@see ClientAccount::$legacy_customer_id}.
 *
 * Supersedes {@see SyncLegacyStripeWhatsappCommand} for production recovery (includes Stripe,
 * WhatsApp, CC fee, plus manager, contract date, Slack, payment prefs, notes, etc.).
 *
 * Prerequisites: import legacy SQL into MySQL and set LEGACY_DB_* (same as crm:import-legacy-clients).
 */
class SyncLegacyAccountFieldsCommand extends Command
{
    protected $signature = 'crm:sync-legacy-account-fields
                            {--connection=legacy_crm : Laravel DB connection name for legacy MySQL}
                            {--customers-table=customers : Legacy customers table name}
                            {--include-deleted : Include legacy rows with is_deleted = 2 (default: skip deleted)}
                            {--force : Overwrite non-empty target fields on client_accounts}
                            {--dry-run : List changes without saving}';

    protected $description = 'Sync legacy customer account fields (manager, contract date, Slack, Stripe, payment prefs, etc.) into client_accounts';

    public function handle(): int
    {
        $connName = (string) $this->option('connection');
        $table = trim((string) $this->option('customers-table'));
        $includeDeleted = (bool) $this->option('include-deleted');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $config = config("database.connections.{$connName}");
        if (! is_array($config) || empty($config['database'])) {
            $this->error("Connection [{$connName}] is not configured or LEGACY_DB_DATABASE is empty.");

            return self::FAILURE;
        }

        if (($config['driver'] ?? '') !== 'mysql') {
            $this->error('This sync requires a MySQL legacy connection.');

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

        if (! $schema->hasTable($table)) {
            $this->error("Legacy table [{$table}] does not exist on [{$connName}].");

            return self::FAILURE;
        }

        if (! $schema->hasColumn($table, 'c_email') && ! $schema->hasColumn($table, 'email')) {
            $this->warn("Legacy table [{$table}] does not look like old CRM customers (no c_email/email). Mapping may be limited.");
        }

        $query = $legacy->table($table);
        if (! $includeDeleted && $schema->hasColumn($table, 'is_deleted')) {
            $query->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', '!=', 2);
            });
        }

        $wouldUpdate = 0;
        $skippedNoAccount = 0;
        $skippedNoLegacyData = 0;
        $skippedAlreadyFilled = 0;
        $unmappedManagers = 0;

        LegacyCustomerAccountImportMapper::clearManagerCache();

        $query->orderBy('id')->chunkById(200, function ($rows) use (
            $force,
            $dryRun,
            &$wouldUpdate,
            &$skippedNoAccount,
            &$skippedNoLegacyData,
            &$skippedAlreadyFilled,
            &$unmappedManagers
        ) {
            foreach ($rows as $row) {
                $legacyId = (int) $row->id;
                $account = ClientAccount::query()->where('legacy_customer_id', $legacyId)->first();
                if ($account === null) {
                    $skippedNoAccount++;

                    continue;
                }

                $contactEmail = LegacyCustomerAccountImportMapper::normalizeScalar($row->c_email ?? $row->email ?? null);
                $mapped = LegacyCustomerAccountImportMapper::mapEnrichmentFields($row, $contactEmail);

                if ($mapped === []) {
                    $skippedNoLegacyData++;

                    continue;
                }

                if (
                    isset($row->manager)
                    && is_numeric($row->manager)
                    && (int) $row->manager > 0
                    && ! isset($mapped['account_manager_id'])
                ) {
                    $unmappedManagers++;
                }

                $attrs = LegacyCustomerAccountImportMapper::mergeForSync($mapped, $account, $force);

                if ($attrs === []) {
                    $skippedAlreadyFilled++;

                    continue;
                }

                if ($dryRun) {
                    $this->line(
                        "legacy_customer_id={$legacyId} client_account_id={$account->id} company="
                        .json_encode((string) $account->company_name).' → '.json_encode($attrs)
                    );
                } else {
                    ClientAccount::query()->whereKey($account->id)->update($attrs);
                }
                $wouldUpdate++;
            }
        }, 'id');

        $this->info($dryRun ? 'Dry run — no rows saved.' : 'Done.');
        $this->table(
            ['Metric', 'Count'],
            [
                [$dryRun ? 'Rows that would update' : 'Rows updated', (string) $wouldUpdate],
                ['Skipped (no client_account for legacy id)', (string) $skippedNoAccount],
                ['Skipped (no mappable legacy data)', (string) $skippedNoLegacyData],
                ['Skipped (target fields already set; use --force)', (string) $skippedAlreadyFilled],
                ['Legacy manager id present but unmapped to users', (string) $unmappedManagers],
            ]
        );

        if ($unmappedManagers > 0) {
            $this->warn('Some accounts have legacy manager ids with no matching users.legacy_user_id or users.name — import staff users first.');
        }

        return self::SUCCESS;
    }
}
