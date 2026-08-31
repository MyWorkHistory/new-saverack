<?php

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Supplies catalog + order history are team-wide. Ensure CRM staff can access them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keys = [
            'resources_supplies.view',
            'resources_supplies.create',
        ];
        Permission::ensureRowsForKeys($keys);
        $ids = Permission::idsForKeys($keys);
        if ($ids === []) {
            return;
        }

        User::query()
            ->whereNull('client_account_id')
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (User $user) use ($ids) {
                $user->permissions()->syncWithoutDetaching($ids);
            });
    }

    public function down(): void
    {
        // Non-destructive: keep staff grants.
    }
};
