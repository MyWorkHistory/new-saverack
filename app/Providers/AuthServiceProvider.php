<?php

namespace App\Providers;

use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountOnDemandProduct;
use App\Models\ClientStore;
use App\Models\CustomBill;
use App\Models\Invoice;
use App\Models\ReturnBill;
use App\Models\AsnBill;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\PricingFeeTemplate;
use App\Models\TermsOfService;
use App\Models\Tutorial;
use App\Models\ResourceCalendarEvent;
use App\Models\ResourcePhoto;
use App\Models\User;
use App\Models\WholesaleOrder;
use App\Policies\ClientAccountAsnPolicy;
use App\Policies\ClientAccountReturnPolicy;
use App\Policies\ClientAccountOnDemandProductPolicy;
use App\Policies\ClientAccountPolicy;
use App\Policies\ClientStorePolicy;
use App\Policies\CustomBillPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PricingFeeTemplatePolicy;
use App\Policies\ReturnBillPolicy;
use App\Policies\AsnBillPolicy;
use App\Policies\ResourceCalendarEventPolicy;
use App\Policies\ResourcePhotoPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TermsOfServicePolicy;
use App\Policies\TicketPolicy;
use App\Policies\TutorialPolicy;
use App\Policies\UserPolicy;
use App\Policies\WholesaleOrderPolicy;
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
        ReturnBill::class => ReturnBillPolicy::class,
        AsnBill::class => AsnBillPolicy::class,
        PricingFeeTemplate::class => PricingFeeTemplatePolicy::class,
        TermsOfService::class => TermsOfServicePolicy::class,
        Ticket::class => TicketPolicy::class,
        Task::class => TaskPolicy::class,
        Tutorial::class => TutorialPolicy::class,
        ResourcePhoto::class => ResourcePhotoPolicy::class,
        ResourceCalendarEvent::class => ResourceCalendarEventPolicy::class,
        WholesaleOrder::class => WholesaleOrderPolicy::class,
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

        Gate::define('receiving.view', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return in_array('receiving.view', $user->allPermissionKeys(), true);
        });

        Gate::define('receiving.update', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return in_array('receiving.update', $user->allPermissionKeys(), true);
        });

        Gate::define('crm.client-account-options', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return true;
            }

            $keys = $user->allPermissionKeys();
            foreach (['inventory.view', 'orders.view', 'receiving.view', 'clients.view', 'billing.view'] as $perm) {
                if (in_array($perm, $keys, true)) {
                    return true;
                }
            }

            return false;
        });

        /**
         * CRM-only product active/inactive (portal own account; staff with inventory.update).
         */
        Gate::define('inventory.crm-status.update', function ($user) {
            if (! $user) {
                return false;
            }

            if (Gate::forUser($user)->allows('inventory.update')) {
                return true;
            }

            return (int) ($user->client_account_id ?? 0) > 0;
        });

        /**
         * ShipHero order mutations: requires explicit orders.update for CRM staff.
         */
        Gate::define('shiphero.orders.write', function ($user) {
            if (! $user) {
                return false;
            }

            if (Gate::forUser($user)->allows('orders.update')) {
                return true;
            }

            // Portal users: manage orders for their client account (controllers enforce scope).
            return (int) ($user->client_account_id ?? 0) > 0;
        });
    }
}
