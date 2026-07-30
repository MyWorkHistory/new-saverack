<?php

namespace App\Policies;

use App\Models\ClientAccount;
use App\Models\LtlShipment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class LtlShipmentPolicy
{
    private function isPortalUser(User $user): bool
    {
        return (int) ($user->client_account_id ?? 0) > 0;
    }

    private function ownsAccount(User $user, int $clientAccountId): bool
    {
        return (int) ($user->client_account_id ?? 0) === $clientAccountId && $clientAccountId > 0;
    }

    public function viewAny(User $user): bool
    {
        if ($this->isPortalUser($user)) {
            return Gate::forUser($user)->allows('inventory.view');
        }

        return Gate::forUser($user)->allows('receiving_ltl.view')
            || Gate::forUser($user)->allows('receiving.view');
    }

    public function view(User $user, LtlShipment $shipment): bool
    {
        if ($this->isPortalUser($user)) {
            if (! Gate::forUser($user)->allows('inventory.view')) {
                return false;
            }

            return $this->ownsAccount($user, (int) $shipment->client_account_id);
        }

        if (! (Gate::forUser($user)->allows('receiving_ltl.view') || Gate::forUser($user)->allows('receiving.view'))) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $shipment->clientAccount);
    }

    public function create(User $user, ClientAccount $account): bool
    {
        if ($this->isPortalUser($user)) {
            if (! Gate::forUser($user)->allows('inventory.view')) {
                return false;
            }

            return $this->ownsAccount($user, (int) $account->id);
        }

        if (! (Gate::forUser($user)->allows('receiving_ltl.create') || Gate::forUser($user)->allows('receiving.update'))) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $account);
    }

    public function update(User $user, LtlShipment $shipment): bool
    {
        if ($this->isPortalUser($user)) {
            if (! $this->view($user, $shipment)) {
                return false;
            }

            // Portal can edit while Draft/Pending; later statuses are view-only.
            return in_array($shipment->status, [LtlShipment::STATUS_DRAFT, LtlShipment::STATUS_PENDING], true);
        }

        if (! (Gate::forUser($user)->allows('receiving_ltl.update') || Gate::forUser($user)->allows('receiving.update'))) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $shipment->clientAccount);
    }

    public function changeStatus(User $user, LtlShipment $shipment): bool
    {
        if ($this->isPortalUser($user)) {
            return false;
        }

        return $this->update($user, $shipment);
    }

    public function delete(User $user, LtlShipment $shipment): bool
    {
        if ($this->isPortalUser($user)) {
            return $this->view($user, $shipment) && $shipment->isDraft();
        }

        if (! (Gate::forUser($user)->allows('receiving_ltl.delete') || Gate::forUser($user)->allows('receiving.update'))) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $shipment->clientAccount);
    }
}
