<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Support\Legacy\LegacyCustomerAccountImportMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Syncs Stripe customer IDs from legacy `customers.stripe_customer_id` into `client_accounts`
 * matched by legacy_customer_id, exact or similar company name (LLC/Inc-insensitive), or email.
 *
 * Use after accounts exist when many Stripe IDs were skipped because legacy_customer_id was unset.
 *
 * Prerequisites: legacy MySQL + LEGACY_DB_* (same as crm:import-legacy-clients).
 */
class SyncLegacyStripeCustomerIdsCommand extends Command
{
    protected $signature = 'crm:sync-legacy-stripe-customer-ids
                            {--connection=legacy_crm : Laravel DB connection name for legacy MySQL}
                            {--customers-table=customers : Legacy customers table name}
                            {--include-deleted : Include legacy rows with is_deleted = 2 (default: skip deleted)}
                            {--force : Overwrite non-empty stripe_customer_id on client_accounts}
                            {--dry-run : List changes without saving}';

    protected $description = 'Sync Stripe customer IDs from legacy customers into client_accounts (by legacy id, similar company name, or email)';

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

        if (! $schema->hasColumn($table, 'stripe_customer_id')) {
            $this->error("Legacy table [{$table}] has no stripe_customer_id column.");

            return self::FAILURE;
        }

        $select = ['id', 'stripe_customer_id'];
        if ($schema->hasColumn($table, 'company_name')) {
            $select[] = 'company_name';
        }
        if ($schema->hasColumn($table, 'c_email')) {
            $select[] = 'c_email';
        }
        if ($schema->hasColumn($table, 'email')) {
            $select[] = 'email';
        }

        $query = $legacy->table($table)->select($select);
        if (! $includeDeleted && $schema->hasColumn($table, 'is_deleted')) {
            $query->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', '!=', 2);
            });
        }

        $wouldUpdate = 0;
        $skippedNoStripe = 0;
        $skippedNoAccount = 0;
        $skippedAlreadyFilled = 0;
        $matchedByLegacyId = 0;
        $matchedByCompanyName = 0;
        $matchedByEmail = 0;
        $failedUpdates = 0;
        $unmatchedWithStripe = [];

        $query->orderBy('id')->chunkById(200, function ($rows) use (
            $force,
            $dryRun,
            &$wouldUpdate,
            &$skippedNoStripe,
            &$skippedNoAccount,
            &$skippedAlreadyFilled,
            &$matchedByLegacyId,
            &$matchedByCompanyName,
            &$matchedByEmail,
            &$failedUpdates,
            &$unmatchedWithStripe
        ) {
            foreach ($rows as $row) {
                $legacyId = (int) $row->id;
                $stripe = LegacyCustomerAccountImportMapper::normalizeScalar($row->stripe_customer_id ?? null);
                if ($stripe === null || $stripe === '') {
                    $skippedNoStripe++;

                    continue;
                }
                $stripe = substr($stripe, 0, 191);

                $account = LegacyCustomerAccountImportMapper::findClientAccountForLegacyRow($row);
                if ($account === null) {
                    $skippedNoAccount++;
                    if (count($unmatchedWithStripe) < 40) {
                        $unmatchedWithStripe[] = [
                            'legacy_id' => $legacyId,
                            'company' => (string) ($row->company_name ?? ''),
                            'stripe' => $stripe,
                        ];
                    }

                    continue;
                }

                $this->countMatch($account, $row, $matchedByLegacyId, $matchedByCompanyName, $matchedByEmail);

                $current = trim((string) ($account->stripe_customer_id ?? ''));
                if (! $force && $current !== '') {
                    $skippedAlreadyFilled++;

                    continue;
                }

                if ($current === $stripe) {
                    $skippedAlreadyFilled++;

                    continue;
                }

                $attrs = ['stripe_customer_id' => $stripe];
                $attrs = array_merge(
                    $attrs,
                    LegacyCustomerAccountImportMapper::legacyCustomerIdBackfill($legacyId, $account, $force)
                );

                if ($dryRun) {
                    $this->line(
                        "legacy_id={$legacyId} → account #{$account->id} "
                        .json_encode((string) $account->company_name)
                        ." stripe={$stripe}"
                    );
                } else {
                    try {
                        ClientAccount::query()->whereKey($account->id)->update($attrs);
                    } catch (\Throwable $e) {
                        $failedUpdates++;
                        $this->warn("Failed legacy_id={$legacyId} account={$account->id}: ".$e->getMessage());

                        continue;
                    }
                }
                $wouldUpdate++;
            }
        }, 'id');

        $this->info($dryRun ? 'Dry run — no rows saved.' : 'Done.');
        $this->table(
            ['Metric', 'Count'],
            [
                [$dryRun ? 'Would set stripe_customer_id' : 'Updated stripe_customer_id', (string) $wouldUpdate],
                ['Matched by legacy_customer_id', (string) $matchedByLegacyId],
                ['Matched by company name (exact or similar)', (string) $matchedByCompanyName],
                ['Matched by email', (string) $matchedByEmail],
                ['Skipped (legacy stripe empty / inactive)', (string) $skippedNoStripe],
                ['Skipped (no matching client_account)', (string) $skippedNoAccount],
                ['Skipped (already has stripe; use --force)', (string) $skippedAlreadyFilled],
                ['Failed updates', (string) $failedUpdates],
            ]
        );

        if ($unmatchedWithStripe !== []) {
            $this->warn('Sample legacy rows with Stripe ID but no matching CRM account:');
            foreach ($unmatchedWithStripe as $sample) {
                $this->line(
                    "  legacy_id={$sample['legacy_id']} company="
                    .json_encode($sample['company'])
                    ." stripe={$sample['stripe']}"
                );
            }
        }

        return self::SUCCESS;
    }

    private function countMatch(
        ClientAccount $account,
        object $row,
        int &$matchedByLegacyId,
        int &$matchedByCompanyName,
        int &$matchedByEmail
    ): void {
        $legacyId = (int) ($row->id ?? 0);
        if ($legacyId > 0 && (int) ($account->legacy_customer_id ?? 0) === $legacyId) {
            $matchedByLegacyId++;

            return;
        }

        $legacyName = LegacyCustomerAccountImportMapper::legacyCompanyNameFromRow($row);
        if ($legacyName !== null) {
            $exact = LegacyCustomerAccountImportMapper::normalizeCompanyName($legacyName)
                === LegacyCustomerAccountImportMapper::normalizeCompanyName((string) $account->company_name);
            $similar = LegacyCustomerAccountImportMapper::companyNameMatchKey($legacyName)
                === LegacyCustomerAccountImportMapper::companyNameMatchKey((string) $account->company_name);
            if ($exact || $similar) {
                $matchedByCompanyName++;

                return;
            }
        }

        $email = strtolower(trim((string) (LegacyCustomerAccountImportMapper::normalizeScalar($row->c_email ?? $row->email ?? null) ?? '')));
        if ($email !== '' && strtolower(trim((string) $account->email)) === $email) {
            $matchedByEmail++;
        }
    }
}
