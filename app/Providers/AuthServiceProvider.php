<?php

namespace App\Providers;

use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountOnDemandProduct;
use App\Models\ClientStore;
use App\Models\CustomBill;
use App\Models\Invoice;
use App\Models\PricingFeeTemplate;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\ClientAccountAsnPolicy;
use App\Policies\ClientAccountReturnPolicy;
use App\Policies\ClientAccountOnDemandProductPolicy;
use App\Policies\ClientAccountPolicy;
use App\Policies\ClientStorePolicy;
use App\Policies\CustomBillPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PricingFeeTemplatePolicy;
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
        ClientAccountAsn::class => ClientAccountAsnPolicy::class,
        ClientAccountReturn::class => ClientAccountReturnPolicy::class,
        ClientAccountOnDemandProduct::class => ClientAccountOnDemandProductPolicy::class,
        ClientStore::class => ClientStorePolicy::class,
        Invoice::class => InvoicePolicy::class,
        CustomBill::class => CustomBillPolicy::class,
        PricingFeeTemplate::class => PricingFeeTemplatePolicy::class,
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
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return true;
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

        Gate::define('orders.view', function ($user) {
            if (! $user) {
                return false;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return true;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return in_array('orders.view', $user->allPermissionKeys(), true);
        });

        Gate::define('orders.update', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return in_array('orders.update', $user->allPermissionKeys(), true);
        });

        /**
         * ShipHero order mutations: gated by explicit orders.update for CRM users.
         * Keep inventory.update as temporary fallback for backwards compatibility.
         */
        Gate::define('shiphero.orders.write', function ($user) {
            if (! $user) {
                return false;
            }

            if (Gate::forUser($user)->allows('orders.update')
                || Gate::forUser($user)->allows('inventory.update')) {
                return true;
            }

            // Staff CRM users with orders.view may mutate orders in admin UI (not portal accounts).
            return $user->client_account_id === null
                && Gate::forUser($user)->allows('orders.view');
        });
    }
}
