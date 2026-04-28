<?php

namespace App\Providers;

use App\Models\ClientAccount;
use App\Models\ClientAccountOnDemandProduct;
use App\Models\ClientStore;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\ClientAccountOnDemandProductPolicy;
use App\Policies\ClientAccountPolicy;
use App\Policies\ClientStorePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\TaskPolicy;
use App\Policies\TicketPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => UserPolicy::class,
        ClientAccount::class => ClientAccountPolicy::class,
        ClientAccountOnDemandProduct::class => ClientAccountOnDemandProductPolicy::class,
        ClientStore::class => ClientStorePolicy::class,
        Invoice::class => InvoicePolicy::class,
        Ticket::class => TicketPolicy::class,
        Task::class => TaskPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Gate::define('inventory.view', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return in_array('inventory.view', $user->allPermissionKeys(), true);
        });

        Gate::define('inventory.update', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return in_array('inventory.update', $user->allPermissionKeys(), true);
        });
    }
}
