<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\Legacy\LegacyStaffUserImportMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Imports active Save Rack staff from legacy `users` into CRM `users` + `user_profiles`.
 *
 * Prerequisites: import `public/users.sql` into LEGACY_DB and set LEGACY_DB_* in `.env`.
 * Run before `crm:sync-legacy-account-fields` so account managers resolve via legacy_user_id.
 */
class ImportLegacyStaffUsersCommand extends Command
{
    protected $signature = 'crm:import-legacy-staff-users
                            {--connection=legacy_crm : Laravel DB connection name for legacy MySQL}
                            {--users-table=users : Legacy users table name}
                            {--password= : Bcrypt password for imported staff (default: config legacy_staff_import.default_password)}
                            {--force : Overwrite existing staff matched by legacy_user_id/email (incl. password)}
                            {--dry-run : List changes without saving}';

    protected $description = 'Import active legacy staff users into CRM users + user_profiles (password defaults to backup)';

    public function handle(): int
    {
        $connName = (string) $this->option('connection');
        $table = trim((string) $this->option('users-table'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $passwordPlain = trim((string) $this->option('password'));
        if ($passwordPlain === '') {
            $passwordPlain = (string) config('legacy_staff_import.default_password', 'backup');
        }
        $passwordHash = Hash::make($passwordPlain);

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

        if (! $schema->hasTable($table)) {
            $this->error("Legacy table [{$table}] does not exist on [{$connName}].");

            return self::FAILURE;
        }

        $adminRole = Role::query()->where('name', 'admin')->first();
        $staffRole = Role::query()->where('name', 'staff')->first();
        if ($adminRole === null || $staffRole === null) {
            $this->error('CRM roles missing. Run: php artisan db:seed --class=RolePermissionSeeder --force');

            return self::FAILURE;
        }

        /** @var array<string, int> $roleIds */
        $roleIds = [
            'admin' => (int) $adminRole->id,
            'staff' => (int) $staffRole->id,
        ];

        $imported = 0;
        $updated = 0;
        $skippedNotStaff = 0;
        $skippedPortalCollision = 0;
        $skippedInvalidEmail = 0;
        $failed = 0;

        $legacy->table($table)->orderBy('id')->chunkById(200, function ($rows) use (
            $passwordHash,
            $force,
            $dryRun,
            $roleIds,
            &$imported,
            &$updated,
            &$skippedNotStaff,
            &$skippedPortalCollision,
            &$skippedInvalidEmail,
            &$failed
        ) {
            foreach ($rows as $row) {
                if (! LegacyStaffUserImportMapper::isImportableStaffRow($row)) {
                    $skippedNotStaff++;

                    continue;
                }

                $email = LegacyStaffUserImportMapper::normEmail($row->email ?? null);
                if (! LegacyStaffUserImportMapper::isValidEmail($email)) {
                    $skippedInvalidEmail++;

                    continue;
                }

                $legacyId = (int) $row->id;
                $existing = User::query()->where('legacy_user_id', $legacyId)->first();
                if ($existing === null) {
                    $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
                }

                if ($existing !== null && $existing->client_account_id !== null) {
                    $skippedPortalCollision++;

                    continue;
                }

                $legacyRole = isset($row->role) && is_numeric($row->role) ? (int) $row->role : 0;
                $crmRoleName = LegacyStaffUserImportMapper::resolveCrmRoleName($legacyRole);
                $roleId = $roleIds[$crmRoleName] ?? $roleIds['staff'];

                $isNew = $existing === null;
                $includePassword = $isNew || $force;
                $userAttrs = LegacyStaffUserImportMapper::mapUserFields(
                    $row,
                    $includePassword ? $passwordHash : null
                );
                $profileAttrs = LegacyStaffUserImportMapper::mapProfileFields($row);

                if ($dryRun) {
                    $action = $isNew ? 'create' : ($force ? 'update+force' : 'update');
                    $this->line(
                        "{$action} legacy_user_id={$legacyId} email=".json_encode($email)
                        .' role='.$crmRoleName.' user='.json_encode(array_keys($userAttrs))
                        .' profile='.json_encode(array_keys($profileAttrs))
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
                        $legacyId,
                        $userAttrs,
                        $profileAttrs,
                        $roleId,
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

                        if ($user->legacy_user_id === null && $legacyId > 0) {
                            $user->legacy_user_id = $legacyId;
                            $user->saveQuietly();
                        }

                        UserProfile::query()->updateOrCreate(
                            ['user_id' => $user->id],
                            $profileAttrs
                        );

                        $user->roles()->sync([$roleId]);
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
                ['Skipped (not active Employee staff)', (string) $skippedNotStaff],
                ['Skipped (email already used by portal user)', (string) $skippedPortalCollision],
                ['Skipped (invalid email)', (string) $skippedInvalidEmail],
                ['Failed', (string) $failed],
            ]
        );

        if (! $dryRun && ($imported > 0 || $updated > 0)) {
            $this->line('Imported staff password: '.$passwordPlain.' (users should change after first login)');
        }

        return self::SUCCESS;
    }
}
