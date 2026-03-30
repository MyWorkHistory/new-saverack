<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Use database/migrations/*_create_personal_access_tokens_table.php (hasTable guard)
        // instead of Sanctum's vendor 2019 migration — avoids "table already exists" on servers
        // where the table was created earlier or outside migrations.
        Sanctum::ignoreMigrations();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('view-dashboard', function (User $user): bool {
            return $user->hasPermission('dashboard.view');
        });
    }
}
