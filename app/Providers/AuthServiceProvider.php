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
use App\Models\Project;
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
use App\Policies\ProjectPolicy;
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
        Project::class => ProjectPolicy::class,
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

            return $user->hasPermission('inventory.view');
        });

        Gate::define('inventory.update', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('inventory.update');
        });

        Gate::define('inventory_location_labels.view', function ($user) {
            if (! $user) {
                return false;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('inventory_location_labels.view');
        });

        Gate::define('inventory_location_labels.create', function ($user) {
            if (! $user) {
                return false;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('inventory_location_labels.create')
                || $user->hasPermission('inventory_location_labels.update');
        });

        Gate::define('inventory_location_labels.update', function ($user) {
            if (! $user) {
                return false;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('inventory_location_labels.update');
        });

        Gate::define('inventory_location_labels.delete', function ($user) {
            if (! $user) {
                return false;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('inventory_location_labels.delete')
                || $user->hasPermission('inventory_location_labels.update');
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

            return $user->hasPermission('orders.view');
        });

        Gate::define('orders.update', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('orders.update');
        });

        Gate::define('receiving.view', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('receiving.view');
        });

        Gate::define('receiving.update', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('receiving.update');
        });

        Gate::define('receiving_asn.view', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('receiving_asn.view');
        });

        Gate::define('receiving_asn.update', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('receiving_asn.update');
        });

        Gate::define('receiving_put_away.view', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('receiving_put_away.view');
        });

        Gate::define('receiving_put_away.update', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('receiving_put_away.update');
        });

        Gate::define('returns.view', function ($user) {
            if (! $user) {
                return false;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return true;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('returns.view');
        });

        Gate::define('returns.update', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('returns.update');
        });

        Gate::define('receiving_asn.create', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('receiving_asn.create');
        });

        Gate::define('receiving_asn.delete', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('receiving_asn.delete');
        });

        Gate::define('receiving_put_away.create', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('receiving_put_away.create');
        });

        Gate::define('receiving_put_away.delete', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('receiving_put_away.delete');
        });

        Gate::define('returns.create', function ($user) {
            if (! $user) {
                return false;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return true;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('returns.create');
        });

        Gate::define('returns.delete', function ($user) {
            if (! $user) {
                return false;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return true;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('returns.delete');
        });

        Gate::define('orders.create', function ($user) {
            if (! $user) {
                return false;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return true;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('orders.create');
        });

        Gate::define('orders.delete', function ($user) {
            if (! $user) {
                return false;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return true;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }

            return $user->hasPermission('orders.delete');
        });

        /**
         * Shared ASN routes (portal + admin hub): portal by account; staff by receiving_asn.*.
         */
        Gate::define('asns.view', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return true;
            }

            return $user->hasPermission('receiving_asn.view');
        });

        Gate::define('asns.create', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return true;
            }

            return $user->hasPermission('receiving_asn.create');
        });

        Gate::define('asns.update', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return true;
            }

            return $user->hasPermission('receiving_asn.update');
        });

        Gate::define('asns.delete', function ($user) {
            if (! $user) {
                return false;
            }
            if ($user->isAdministrator() || $user->isCrmOwner()) {
                return true;
            }
            if ((int) ($user->client_account_id ?? 0) > 0) {
                return true;
            }

            return $user->hasPermission('receiving_asn.delete');
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

            foreach (['inventory.view', 'orders.view', 'receiving.view', 'returns.view', 'clients.view', 'billing.view', 'projects.view'] as $perm) {
                if ($user->hasPermission($perm)) {
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

            if (
                Gate::forUser($user)->allows('orders.update')
                || Gate::forUser($user)->allows('orders.create')
                || Gate::forUser($user)->allows('orders.delete')
            ) {
                return true;
            }

            // Portal users: manage orders for their client account (controllers enforce scope).
            return (int) ($user->client_account_id ?? 0) > 0;
        });
    }
}
