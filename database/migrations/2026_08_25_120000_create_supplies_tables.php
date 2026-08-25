<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\CrmStaffPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplies', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->string('name');
            $table->string('link', 2048)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['type', 'sort_order']);
        });

        Schema::create('supply_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index('submitted_at');
        });

        Schema::create('supply_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_order_id')->constrained('supply_orders')->cascadeOnDelete();
            $table->foreignId('supply_id')->nullable()->constrained('supplies')->nullOnDelete();
            $table->string('name');
            $table->string('type', 64);
            $table->string('link', 2048)->nullable();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index(['name', 'type']);
        });

        foreach (CrmStaffPermissionCatalog::definitions() as $def) {
            Permission::query()->firstOrCreate(
                ['key' => $def['key']],
                ['label' => $def['label'], 'module' => $def['module']]
            );
        }

        $keys = [
            'resources_supplies.view',
            'resources_supplies.create',
            'resources_supplies.update',
            'resources_supplies.delete',
        ];
        Permission::ensureRowsForKeys($keys);
        $ids = Permission::idsForKeys($keys);

        $admin = Role::query()->where('name', 'admin')->first();
        if ($admin && $ids !== []) {
            $admin->permissions()->syncWithoutDetaching($ids);
        }

        // Backfill anyone with legacy resources.view
        $legacyViewId = Permission::query()->where('key', 'resources.view')->value('id');
        $viewId = Permission::query()->where('key', 'resources_supplies.view')->value('id');
        if ($legacyViewId && $viewId) {
            $userIds = \Illuminate\Support\Facades\DB::table('permission_user')
                ->where('permission_id', $legacyViewId)
                ->pluck('user_id');
            foreach ($userIds as $userId) {
                \Illuminate\Support\Facades\DB::table('permission_user')->insertOrIgnore([
                    'permission_id' => $viewId,
                    'user_id' => (int) $userId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_order_lines');
        Schema::dropIfExists('supply_orders');
        Schema::dropIfExists('supplies');
    }
};
