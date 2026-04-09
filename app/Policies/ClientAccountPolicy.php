<?php

namespace App\Policies;

use App\Models\ClientAccount;
use App\Models\User;

class ClientAccountPolicy
{
    private function canManageClients(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->canManageClients($user) || $user->hasPermission('clients.view');
    }

    public function view(User $user, ClientAccount $clientAccount): bool
    {
        return $this->canManageClients($user) || $user->hasPermission('clients.view');
    }

    public function create(User $user): bool
    {
        return $this->canManageClients($user) || $user->hasPermission('clients.create');
    }

    public function update(User $user, ClientAccount $clientAccount): bool
    {
        return $this->canManageClients($user) || $user->hasPermission('clients.update');
    }

    /** Internal notes / threaded comments on the account profile. */
    public function comment(User $user, ClientAccount $clientAccount): bool
    {
        return $this->update($user, $clientAccount);
    }

    public function delete(User $user, ClientAccount $clientAccount): bool
    {
        return $this->canManageClients($user) || $user->hasPermission('clients.delete');
    }

    public function viewStores(User $user, ClientAccount $clientAccount): bool
    {
        if (! $this->view($user, $clientAccount)) {
            return false;
        }
        if ($this->canManageClients($user)) {
            return true;
        }

        return $user->hasPermission('stores.view');
    }

    public function createStore(User $user, ClientAccount $clientAccount): bool
    {
        if (! $this->view($user, $clientAccount)) {
            return false;
        }
        if ($this->canManageClients($user)) {
            return true;
        }

        return $user->hasPermission('stores.create');
    }
}
