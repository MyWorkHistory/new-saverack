<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'client'],
            [
                'label' => '3PL Client',
                'description' => 'Self-service / portal 3PL accounts',
                'is_system' => true,
            ]
        );
    }

    public function down(): void
    {
        $r = Role::query()->where('name', 'client')->first();
        if ($r !== null) {
            $r->permissions()->detach();
            $r->users()->detach();
            $r->delete();
        }
    }
};
