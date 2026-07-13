<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\Legacy\LegacyPortalUserImportMapper;
use App\Support\Legacy\LegacyPortalUserLinkResolver;
use App\Support\Legacy\LegacyStaffUserImportMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Imports client portal users from legacy `users` into CRM portal users.
 *
 * Prerequisites: legacy `users`, `customers`, and link table (`accounts` / `user_customers` / `customer_users`) in LEGACY_DB; client accounts imported first.
 */
class ImportLegacyPortalUsersCommand extends Command
{
    protected $signature = 'crm:import-legacy-portal-users
                            {--connection=legacy_crm : Laravel DB connection name for legacy MySQL}
                            {--users-table=users : Legacy users table name}
                            {--customers-table=customers : Legacy customers (accounts) table for company-name matching}
                            {--links-table=auto : Legacy user↔customer link table: auto, accounts, user_customers, customer_users, or none}
                            {--password= : Bcrypt password when legacy hash is missing (default: config legacy_portal_import.default_password)}
                            {--include-pending : Import legacy status 1 (pending) rows}
                            {--include-inactive : Import legacy status 3 (inactive) rows}
                            {--include-deleted : Import legacy is_deleted = 2 rows}
                            {--force : Overwrite existing portal users matched by legacy_user_id/email}
                            {--dry-run : List changes without saving}';

    protected $description = 'Import legacy client portal users into CRM portal users (linked to client accounts)';

    public function handle(): int
    {
        $connName = (string) $this->option('connection');
        $usersTable = trim((string) $this->option('users-table'));
        $customersTable = trim((string) $this->option('customers-table'));
        $linksTableOpt = trim((string) $this->option('links-table'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $includePending = (bool) $this->option('include-pending');
        $includeInactive = (bool) $this->option('include-inactive');
        $includeDeleted = (bool) $this->option('include-deleted');

        $passwordPlain = trim((string) $this->option('password'));
        if ($passwordPlain === '') {
            $passwordPlain = (string) config('legacy_portal_import.default_password', 'backup');
        }
        $fallbackPasswordHash = Hash::make($passwordPlain);

        $config = config("database.connections.{$connName}");
        if (! is_array($config) || empty($config['database'])) {
            $this->error("Connection [{$connName}] is not configured or LEGACY_DB_DATABASE is empty.");

            return self::FAILURE;
        }

        if (($config['driver'] ?? '') !== 'mysql') {
            $this->error('This import requires a MySQL legacy connection.');

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

        if (! $schema->hasTable($usersTable)) {
            $this->error("Legacy table [{$usersTable}] does not exist on [{$connName}].");

            return self::FAILURE;
        }

        $hasCustomersTable = $customersTable !== '' && $schema->hasTable($customersTable);

        $linkTables = [];
        if (strcasecmp($linksTableOpt, 'none') !== 0) {
            $linkTables = LegacyPortalUserLinkResolver::discoverLinkTables($schema, $linksTableOpt);
        }

        if ($linkTables !== []) {
            foreach ($linkTables as $link) {
                $this->line(
                    'Using legacy user↔customer links: `'.$link['table'].'`'
                    .' ('.$link['user_column'].' → '.$link['customer_column'].')'
                );
            }
        } elseif (strcasecmp($linksTableOpt, 'none') !== 0) {
            $this->warn(
                'No legacy user↔customer link table found (expected accounts, user_customers, or customer_users).'
                .' Matching will rely on email + customers.c_email/b_email only.'
            );
        }

        $userCustomerMap = $linkTables !== []
            ? LegacyPortalUserLinkResolver::buildUserCustomerMap($legacy, $linkTables)
            : [];

        if ($userCustomerMap !== []) {
            $this->line('Loaded '.count($userCustomerMap).' legacy users with customer links.');
        }

        /** @var array<int, object|null> $legacyCustomerCache */
        $legacyCustomerCache = [];

        $imported = 0;
        $updated = 0;
        $skippedNotPortal = 0;
        $skippedStaffCollision = 0;
        $skippedNoAccount = 0;
        $skippedInvalidEmail = 0;
        $skippedExisting = 0;
        $demotedPrimary = 0;
        $matchedByEmail = 0;
        $matchedByLegacyLink = 0;
        $failed = 0;

        $legacy->table($usersTable)->orderBy('id')->chunkById(200, function ($rows) use (
            $legacy,
            $customersTable,
            $hasCustomersTable,
            $userCustomerMap,
            &$legacyCustomerCache,
            $fallbackPasswordHash,
            $force,
            $dryRun,
            $includePending,
            $includeInactive,
            $includeDeleted,
            &$imported,
            &$updated,
            &$skippedNotPortal,
            &$skippedStaffCollision,
            &$skippedNoAccount,
            &$skippedInvalidEmail,
            &$skippedExisting,
            &$demotedPrimary,
            &$matchedByEmail,
            &$matchedByLegacyLink,
            &$failed
        ) {
            foreach ($rows as $row) {
                if (! LegacyPortalUserImportMapper::isImportablePortalRow(
                    $row,
                    $includePending,
                    $includeInactive,
                    $includeDeleted
                )) {
                    $skippedNotPortal++;

                    continue;
                }

                $email = LegacyStaffUserImportMapper::normEmail($row->email ?? null);
                if (! LegacyStaffUserImportMapper::isValidEmail($email)) {
                    $skippedInvalidEmail++;

                    continue;
                }

                $legacyId = (int) $row->id;

                $legacyCustomerRow = null;
                if ($hasCustomersTable) {
                    $legacyCustomerRow = $legacy->table($customersTable)
                        ->where(function ($q) use ($email) {
                            $q->whereRaw('LOWER(TRIM(c_email)) = ?', [$email])
                                ->orWhereRaw('LOWER(TRIM(b_email)) = ?', [$email]);
                        })
                        ->orderBy('id')
                        ->first();
                }

                $linkedCustomerIds = LegacyPortalUserLinkResolver::linkedCustomerIdsForUser(
                    $legacyId,
                    $userCustomerMap,
                    $row
                );
                $linkedLegacyCustomerRows = $hasCustomersTable
                    ? LegacyPortalUserLinkResolver::fetchLegacyCustomerRows(
                        $legacy,
                        $customersTable,
                        $linkedCustomerIds,
                        $legacyCustomerCache
                    )
                    : [];

                $account = LegacyPortalUserImportMapper::findClientAccountForPortalUser(
                    $row,
                    $legacyCustomerRow,
                    $linkedLegacyCustomerRows
                );
                if ($account === null) {
                    $skippedNoAccount++;

                    continue;
                }

                $matchSource = self::resolveMatchSource(
                    $row,
                    $account,
                    $legacyCustomerRow,
                    $linkedLegacyCustomerRows
                );
                if ($matchSource === 'email') {
                    $matchedByEmail++;
                } elseif ($matchSource === 'legacy_link') {
                    $matchedByLegacyLink++;
                }

                $existing = User::query()->where('legacy_user_id', $legacyId)->first();
                if ($existing === null) {
                    $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
                }

                if ($existing !== null && $existing->client_account_id === null) {
                    $skippedStaffCollision++;

                    continue;
                }

                $isNew = $existing === null;
                if (! $isNew && ! $force) {
                    $skippedExisting++;

                    continue;
                }

                $allowPrimary = ! $account->primaryAccountUser()->exists();
                if (! $allowPrimary && LegacyPortalUserImportMapper::isPrimaryPortalUser($row, $account)) {
                    $demotedPrimary++;
                }

                $passwordHash = LegacyPortalUserImportMapper::resolvePasswordHash($row, $fallbackPasswordHash);
                $userAttrs = LegacyPortalUserImportMapper::mapUserFields(
                    $row,
                    $account,
                    $passwordHash,
                    $allowPrimary
                );
                $profileAttrs = LegacyPortalUserImportMapper::mapProfileFields($row);

                if ($dryRun) {
                    $action = $isNew ? 'create' : 'update+force';
                    $this->line(
                        "{$action} legacy_user_id={$legacyId} email=".json_encode($email)
                        .' account_id='.$account->id
                        .' primary='.($userAttrs['is_account_primary'] ? 'yes' : 'no')
                        .' role='.$userAttrs['account_user_role']
                    );
                    if ($isNew) {
                        $imported++;
                    } else {
                        $updated++;
                    }

                    continue;
                }

                try {
                    DB::transaction(function () use (
                        $existing,
                        $userAttrs,
                        $profileAttrs,
                        $isNew,
                        &$imported,
                        &$updated
                    ) {
                        if ($existing !== null) {
                            $existing->update($userAttrs);
                            $user = $existing->fresh();
                            $updated++;
                        } else {
                            $user = User::query()->create($userAttrs);
                            $imported++;
                        }

                        if ($user === null) {
                            return;
                        }

                        UserProfile::query()->updateOrCreate(
                            ['user_id' => $user->id],
                            $profileAttrs
                        );

                        $user->roles()->sync([]);
                    });
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("Failed legacy_user_id={$legacyId} email={$email}: ".$e->getMessage());
                }
            }
        }, 'id');

        $this->info($dryRun ? 'Dry run — no rows saved.' : 'Done.');
        $this->table(
            ['Metric', 'Count'],
            [
                [$dryRun ? 'Rows that would be created' : 'Users created', (string) $imported],
                [$dryRun ? 'Rows that would be updated' : 'Users updated', (string) $updated],
                ['Skipped (not importable portal row)', (string) $skippedNotPortal],
                ['Skipped (no matching client account)', (string) $skippedNoAccount],
                ['Skipped (email already used by staff user)', (string) $skippedStaffCollision],
                ['Skipped (portal user exists; use --force)', (string) $skippedExisting],
                ['Skipped (invalid email)', (string) $skippedInvalidEmail],
                ['Matched via portal email', (string) $matchedByEmail],
                ['Matched via legacy customer link / company name', (string) $matchedByLegacyLink],
                ['Primary demoted (account already has primary)', (string) $demotedPrimary],
                ['Failed', (string) $failed],
            ]
        );

        if (! $dryRun && ($imported > 0 || $updated > 0)) {
            $this->line('Users without a legacy bcrypt hash use password: '.$passwordPlain);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<object>  $linkedLegacyCustomerRows
     */
    private static function resolveMatchSource(
        object $row,
        ClientAccount $account,
        ?object $legacyCustomerRow,
        array $linkedLegacyCustomerRows
    ): string {
        $email = LegacyStaffUserImportMapper::normEmail($row->email ?? null);
        $accountEmail = LegacyStaffUserImportMapper::normEmail($account->email ?? null);
        $notificationEmail = LegacyStaffUserImportMapper::normEmail($account->notification_email ?? null);

        if ($email !== '' && ($email === $accountEmail || $email === $notificationEmail)) {
            return 'email';
        }

        if ($legacyCustomerRow !== null || $linkedLegacyCustomerRows !== []) {
            return 'legacy_link';
        }

        return 'other';
    }
}
