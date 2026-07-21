<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\LocationLabelSqlImporter;
use App\Support\CrmStaffPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beta_locations')) {
            Schema::create('beta_locations', function (Blueprint $table) {
                $table->id();
                $table->string('location')->nullable();
                $table->string('type')->nullable();
                $table->string('label')->nullable();
                $table->boolean('is_deleted')->nullable()->default(false);
                $table->timestamps();
                $table->index(['is_deleted', 'location']);
                $table->index(['is_deleted', 'type']);
            });
        }

        $dumpPath = database_path('data/beta_locations.sql');
        $shouldImportDump = is_file($dumpPath)
            && DB::table('beta_locations')->count() === 0
            && ! app()->environment('testing');

        if ($shouldImportDump) {
            $result = app(LocationLabelSqlImporter::class)->importFromDump($dumpPath);
            if (($result['imported'] ?? 0) > 0 && Schema::getConnection()->getDriverName() === 'mysql') {
                $maxId = (int) DB::table('beta_locations')->max('id');
                if ($maxId > 0) {
                    DB::statement('ALTER TABLE beta_locations AUTO_INCREMENT = '.($maxId + 1));
                }
            }
        }

        foreach (CrmStaffPermissionCatalog::definitions() as $def) {
            if (strpos($def['key'], 'inventory_location_labels.') !== 0) {
                continue;
            }
            Permission::query()->firstOrCreate(
                ['key' => $def['key']],
                ['label' => $def['label'], 'module' => $def['module']]
            );
        }

        $childKeys = [
            'inventory_location_labels.view',
            'inventory_location_labels.create',
            'inventory_location_labels.update',
            'inventory_location_labels.delete',
        ];
        Permission::ensureRowsForKeys($childKeys);
        $childIds = Permission::idsForKeys($childKeys);

        $admin = Role::query()->where('name', 'admin')->first();
        if ($admin !== null && $childIds !== []) {
            $admin->permissions()->syncWithoutDetaching($childIds);
        }

        $expandMap = [
            'inventory.view' => ['inventory_location_labels.view'],
            'inventory.create' => ['inventory_location_labels.create'],
            'inventory.update' => [
                'inventory_location_labels.update',
                'inventory_location_labels.create',
                'inventory_location_labels.delete',
            ],
            'inventory.delete' => ['inventory_location_labels.delete'],
        ];

        $idByKey = Permission::query()
            ->whereIn('key', array_values(array_unique(array_merge(
                array_keys($expandMap),
                ...array_values($expandMap)
            ))))
            ->pluck('id', 'key')
            ->all();

        DB::transaction(function () use ($expandMap, $idByKey) {
            foreach ($expandMap as $legacyKey => $childKeys) {
                $legacyId = isset($idByKey[$legacyKey]) ? (int) $idByKey[$legacyKey] : 0;
                if ($legacyId <= 0) {
                    continue;
                }
                $childIds = [];
                foreach ($childKeys as $ck) {
                    if (isset($idByKey[$ck])) {
                        $childIds[] = (int) $idByKey[$ck];
                    }
                }
                if ($childIds === []) {
                    continue;
                }

                $userIds = DB::table('permission_user')
                    ->where('permission_id', $legacyId)
                    ->pluck('user_id')
                    ->map(static function ($id) {
                        return (int) $id;
                    })
                    ->all();

                foreach ($userIds as $userId) {
                    if ($userId <= 0) {
                        continue;
                    }
                    $user = User::query()->find($userId);
                    if (! $user instanceof User || $user->isAdministrator()) {
                        continue;
                    }
                    $user->permissions()->syncWithoutDetaching($childIds);
                }

                $roleIds = DB::table('permission_role')
                    ->where('permission_id', $legacyId)
                    ->pluck('role_id')
                    ->map(static function ($id) {
                        return (int) $id;
                    })
                    ->all();

                foreach ($roleIds as $roleId) {
                    $role = Role::query()->find($roleId);
                    if (! $role instanceof Role) {
                        continue;
                    }
                    $role->permissions()->syncWithoutDetaching($childIds);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beta_locations');
    }
};
