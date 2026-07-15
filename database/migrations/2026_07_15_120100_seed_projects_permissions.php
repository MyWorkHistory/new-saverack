<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Permission::ensureRowsForKeys([
            'projects.view',
            'projects.create',
            'projects.update',
            'projects.delete',
        ]);
    }

    public function down(): void
    {
        Permission::query()
            ->whereIn('key', [
                'projects.view',
                'projects.create',
                'projects.update',
                'projects.delete',
            ])
            ->delete();
    }
};
