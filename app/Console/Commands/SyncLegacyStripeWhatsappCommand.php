<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off sync: copies Stripe customer id and WhatsApp Cloud API chat id from the old CRM
 * `customers` table (`stripe_customer_id`, `wp_chat_id`) into `client_accounts`
 * matched by {@see ClientAccount::$legacy_customer_id}.
 *
 * Prerequisites: import `database/customers (1).sql` (or your dump) into MySQL and set
 * LEGACY_DB_* in `.env` (same as `crm:import-legacy-clients`).
 */
class SyncLegacyStripeWhatsappCommand extends Command
{
    protected $signature = 'crm:sync-legacy-stripe-whatsapp
                            {--connection=legacy_crm : Laravel DB connection name for legacy MySQL}
                            {--customers-table=customers : Legacy customers table name}
                            {--include-deleted : Include legacy rows with is_deleted = 2 (default: skip deleted)}
                            {--force : Overwrite non-empty stripe_customer_id / whatsapp_api_id on client_accounts}
                            {--dry-run : List changes without saving}';

    protected $description = 'Sync Stripe + WhatsApp API ids from legacy customers into client_accounts (by legacy_customer_id)';

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

        $hasStripe = $schema->hasColumn($table, 'stripe_customer_id');
        $hasWp = $schema->hasColumn($table, 'wp_chat_id');
        if (! $hasStripe && ! $hasWp) {
            $this->error("Legacy table [{$table}] has neither stripe_customer_id nor wp_chat_id.");

            return self::FAILURE;
        }

        $select = ['id'];
        if ($hasStripe) {
            $select[] = 'stripe_customer_id';
        }
        if ($hasWp) {
            $select[] = 'wp_chat_id';
        }

        $query = $legacy->table($table)->select($select);
        if (! $includeDeleted && $schema->hasColumn($table, 'is_deleted')) {
            $query->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', '!=', 2);
            });
        }

        $wouldUpdate = 0;
        $skippedNoAccount = 0;
        $skippedNoLegacyData = 0;
        $skippedAlreadyFilled = 0;

        $query->orderBy('id')->chunkById(200, function ($rows) use (
            $hasStripe,
            $hasWp,
            $force,
            $dryRun,
            &$wouldUpdate,
            &$skippedNoAccount,
            &$skippedNoLegacyData,
            &$skippedAlreadyFilled
        ) {
            foreach ($rows as $row) {
                $legacyId = (int) $row->id;
                $account = ClientAccount::query()->where('legacy_customer_id', $legacyId)->first();
                if ($account === null) {
                    $skippedNoAccount++;

                    continue;
                }

                $stripe = $hasStripe ? $this->normalizeScalar($row->stripe_customer_id ?? null) : null;
                $wa = $hasWp ? $this->normalizeScalar($row->wp_chat_id ?? null) : null;

                if (($stripe === null || $stripe === '') && ($wa === null || $wa === '')) {
                    $skippedNoLegacyData++;

                    continue;
                }

                $attrs = [];
                if ($stripe !== null && $stripe !== '') {
                    $current = (string) ($account->stripe_customer_id ?? '');
                    if ($force || $current === '') {
                        $attrs['stripe_customer_id'] = substr($stripe, 0, 191);
                    }
                }
                if ($wa !== null && $wa !== '') {
                    $currentWa = (string) ($account->whatsapp_api_id ?? '');
                    if ($force || $currentWa === '') {
                        $attrs['whatsapp_api_id'] = substr($wa, 0, 191);
                    }
                }

                if ($attrs === []) {
                    $skippedAlreadyFilled++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("legacy_customer_id={$legacyId} client_account_id={$account->id} company=".json_encode((string) $account->company_name).' → '.json_encode($attrs));
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
                ['Skipped (no stripe / wp_chat_id in legacy)', (string) $skippedNoLegacyData],
                ['Skipped (target fields already set; use --force)', (string) $skippedAlreadyFilled],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * @param  mixed  $v
     */
    private function normalizeScalar($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $lower = strtolower($s);
        if ($lower === 'null' || $lower === 'inactive') {
            return null;
        }

        return $s;
    }
}
