<?php

namespace App\Policies;

use App\Models\ClientStore;
use App\Models\User;

class ClientStorePolicy
{
    private function bypass(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    private function canViewAccount(User $user, ClientStore $store): bool
    {
        $account = $store->clientAccount;

        return $account !== null && $user->can('view', $account);
    }

    public function update(User $user, ClientStore $clientStore): bool
    {
        if (! $this->canViewAccount($user, $clientStore)) {
            return false;
        }
        if ($this->bypass($user)) {
            return true;
        }

        return $user->hasPermission('stores.update');
    }

    public function delete(User $user, ClientStore $clientStore): bool
    {
        if (! $this->canViewAccount($user, $clientStore)) {
            return false;
        }
        if ($this->bypass($user)) {
            return true;
        }

        return $user->hasPermission('stores.delete');
    }
}
